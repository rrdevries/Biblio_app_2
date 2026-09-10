<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Metadata\OpenLibrary;

use Biblio\Core\Application\Metadata\Discovery\BibliographicCandidateType;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryCandidate;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryQuery;
use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderDiscoveryResult;
use Biblio\Core\Application\Metadata\Discovery\BibliographicTextDiscoveryProvider;
use Biblio\Core\Application\Metadata\Discovery\BibliographicTextQuery;
use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderHttpClient;
use Biblio\Core\Application\Metadata\ProviderHttpRequest;
use Biblio\Core\Application\Metadata\ProviderHttpResultStatus;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\IsbnCanonicalizer;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class OpenLibraryTextDiscoveryProvider implements
    BibliographicTextDiscoveryProvider
{
    private const string PROVIDER_KEY = "open_library";
    private const int MAXIMUM_WORKS = 3;
    private const int MAXIMUM_EDITIONS_PER_WORK = 4;
    private const int MAXIMUM_RESPONSE_BYTES = 262144;

    public function __construct(
        private ProviderHttpClient $http,
        private MetadataClock $clock,
        private IsbnCanonicalizer $canonicalizer,
        private OpenLibraryConfiguration $configuration
    ) {
    }

    public function key(): string { return self::PROVIDER_KEY; }

    public function search(
        BibliographicTextQuery $query,
        BibliographicDiscoveryQuery $identity
    ): BibliographicProviderDiscoveryResult {
        $request = new ProviderHttpRequest(
            "https://openlibrary.org/search.json?" . http_build_query([
                "q" => $query->value(),
                "fields" => "key,title,author_name",
                "limit" => self::MAXIMUM_WORKS,
            ], "", "&", PHP_QUERY_RFC3986),
            [
                "Accept" => "application/json",
                "User-Agent" => $this->configuration->userAgent(),
            ],
            4.0,
            self::MAXIMUM_RESPONSE_BYTES
        );
        $result = $this->http->get($request);
        $failure = $this->failure(
            $result->status(),
            $result->status() === ProviderHttpResultStatus::Response
                ? $result->requireResponse()->statusCode()
                : null
        );
        if ($failure !== null) { return $failure; }

        try {
            $payload = $this->object($result->requireResponse()->body());
            $found = $this->nonNegativeInteger($payload, "numFound", "num_found");
            if ($found === 0) { return BibliographicProviderDiscoveryResult::miss(); }
            if (!isset($payload->docs) || !is_array($payload->docs)
                || $payload->docs === [] || count($payload->docs) > self::MAXIMUM_WORKS) {
                return $this->malformed();
            }

            $candidates = [];
            $order = 0;
            foreach ($payload->docs as $document) {
                if (!$document instanceof stdClass) { return $this->malformed(); }
                $workKey = $this->workKey($this->requiredString($document, "key", 64));
                $title = $this->requiredString($document, "title", 512);
                $contributors = $this->strings($document, "author_name", 32, 255);
                $candidates[] = BibliographicDiscoveryCandidate::external(
                    BibliographicCandidateType::ExternalWork,
                    self::PROVIDER_KEY,
                    $workKey,
                    $workKey,
                    $this->clock->now(),
                    MetadataMatchMethod::TextSearch,
                    $identity,
                    $title,
                    null,
                    null,
                    $contributors,
                    [],
                    [],
                    null,
                    null,
                    null,
                    $order++
                );
                $editions = $this->editions(
                    $workKey,
                    $identity,
                    $contributors,
                    $order
                );
                if ($editions instanceof BibliographicProviderDiscoveryResult) {
                    return $editions;
                }
                foreach ($editions as $edition) {
                    $candidates[] = $edition;
                    ++$order;
                }
            }

            return BibliographicProviderDiscoveryResult::candidates($candidates);
        } catch (JsonException|InvalidArgumentException) {
            return $this->malformed();
        }
    }

    /**
     * @param list<string> $workContributors
     * @return list<BibliographicDiscoveryCandidate>|BibliographicProviderDiscoveryResult
     */
    private function editions(
        string $workKey,
        BibliographicDiscoveryQuery $query,
        array $workContributors,
        int $startOrder
    ): array|BibliographicProviderDiscoveryResult {
        $request = new ProviderHttpRequest(
            "https://openlibrary.org{$workKey}/editions.json?" . http_build_query([
                "limit" => self::MAXIMUM_EDITIONS_PER_WORK,
            ], "", "&", PHP_QUERY_RFC3986),
            [
                "Accept" => "application/json",
                "User-Agent" => $this->configuration->userAgent(),
            ],
            4.0,
            self::MAXIMUM_RESPONSE_BYTES
        );
        $result = $this->http->get($request);
        $failure = $this->failure(
            $result->status(),
            $result->status() === ProviderHttpResultStatus::Response
                ? $result->requireResponse()->statusCode()
                : null
        );
        if ($failure !== null) { return $failure; }

        try {
            $payload = $this->object($result->requireResponse()->body());
            if (!isset($payload->entries) || !is_array($payload->entries)
                || count($payload->entries) > self::MAXIMUM_EDITIONS_PER_WORK) {
                return $this->malformed();
            }
            $editions = [];
            foreach ($payload->entries as $offset => $entry) {
                if (!$entry instanceof stdClass) { return $this->malformed(); }
                $editionKey = $this->editionKey($this->requiredString($entry, "key", 64));
                $title = $this->requiredString($entry, "title", 512);
                $editions[] = BibliographicDiscoveryCandidate::external(
                    BibliographicCandidateType::ExternalEdition,
                    self::PROVIDER_KEY,
                    $editionKey,
                    $workKey,
                    $this->clock->now(),
                    MetadataMatchMethod::TextSearch,
                    $query,
                    $title,
                    $this->isbn($entry),
                    $this->optionalString($entry, "subtitle", 512),
                    $workContributors,
                    $this->languageKeys($entry),
                    $this->strings($entry, "publishers", 16, 255),
                    $this->optionalString($entry, "publish_date", 64),
                    $this->optionalInteger($entry, "number_of_pages"),
                    $this->optionalString($entry, "physical_format", 128),
                    $startOrder + $offset
                );
            }
            return $editions;
        } catch (JsonException|InvalidArgumentException) {
            return $this->malformed();
        }
    }

    private function isbn(stdClass $entry): ?CanonicalIsbnIdentity
    {
        $identifiers = [
            ...$this->strings($entry, "isbn_13", 16, 32),
            ...$this->strings($entry, "isbn_10", 16, 32),
        ];
        $identity = null;
        foreach ($identifiers as $identifier) {
            $parsed = $this->canonicalizer->parse($identifier);
            if (!$parsed->isValid() || $parsed->identity() === null) { return null; }
            if ($identity !== null && $identity->isbn13()->value()
                !== $parsed->identity()->isbn13()->value()) { return null; }
            $identity = $parsed->identity();
        }
        return $identity;
    }

    /** @return list<string> */
    private function languageKeys(stdClass $entry): array
    {
        if (!property_exists($entry, "languages") || $entry->languages === null) { return []; }
        if (!is_array($entry->languages) || count($entry->languages) > 16) {
            throw new InvalidArgumentException("Invalid language list.");
        }
        $values = [];
        foreach ($entry->languages as $language) {
            if (!$language instanceof stdClass) { throw new InvalidArgumentException("Invalid language."); }
            $key = $this->requiredString($language, "key", 64);
            if (preg_match('#^/languages/([a-z]{2,8})$#D', $key, $matches) !== 1) {
                throw new InvalidArgumentException("Invalid language key.");
            }
            $values[] = $matches[1];
        }
        return array_values(array_unique($values));
    }

    private function failure(ProviderHttpResultStatus $status, ?int $httpStatus): ?BibliographicProviderDiscoveryResult
    {
        if ($status === ProviderHttpResultStatus::Timeout) {
            return BibliographicProviderDiscoveryResult::failure(ProviderLookupStatus::Unavailable, ProviderFailureReason::Timeout);
        }
        if ($status === ProviderHttpResultStatus::NetworkFailure) {
            return BibliographicProviderDiscoveryResult::failure(ProviderLookupStatus::Unavailable, ProviderFailureReason::Network);
        }
        if ($httpStatus === 429) {
            return BibliographicProviderDiscoveryResult::failure(ProviderLookupStatus::RateLimited, ProviderFailureReason::RateLimited);
        }
        if ($httpStatus !== null && $httpStatus >= 500) {
            return BibliographicProviderDiscoveryResult::failure(ProviderLookupStatus::Unavailable, ProviderFailureReason::Http5xx);
        }
        if ($httpStatus !== null && ($httpStatus < 200 || $httpStatus >= 300)) {
            return BibliographicProviderDiscoveryResult::failure(ProviderLookupStatus::Unavailable, ProviderFailureReason::HttpError);
        }
        return null;
    }

    private function malformed(): BibliographicProviderDiscoveryResult
    {
        return BibliographicProviderDiscoveryResult::failure(ProviderLookupStatus::InvalidResponse, ProviderFailureReason::Malformed);
    }

    private function object(string $body): stdClass
    {
        if (strlen($body) > self::MAXIMUM_RESPONSE_BYTES) { throw new InvalidArgumentException("Response too large."); }
        $value = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
        if (!$value instanceof stdClass) { throw new InvalidArgumentException("Response is not an object."); }
        return $value;
    }

    private function nonNegativeInteger(stdClass $object, string ...$keys): int
    {
        foreach ($keys as $key) {
            if (property_exists($object, $key)) {
                if (!is_int($object->{$key}) || $object->{$key} < 0) {
                    throw new InvalidArgumentException("Invalid result count.");
                }
                return $object->{$key};
            }
        }
        throw new InvalidArgumentException("Missing result count.");
    }

    private function workKey(string $value): string
    {
        $value = str_starts_with($value, "/works/") ? $value : "/works/" . $value;
        if (preg_match('#^/works/OL[0-9]+W$#D', $value) !== 1) {
            throw new InvalidArgumentException("Invalid Work key.");
        }
        return $value;
    }

    private function editionKey(string $value): string
    {
        $value = str_starts_with($value, "/books/") ? $value : "/books/" . $value;
        if (preg_match('#^/books/OL[0-9]+M$#D', $value) !== 1) {
            throw new InvalidArgumentException("Invalid Edition key.");
        }
        return $value;
    }

    private function requiredString(stdClass $object, string $field, int $max): string
    {
        return $this->optionalString($object, $field, $max)
            ?? throw new InvalidArgumentException("Missing provider text.");
    }

    private function optionalString(stdClass $object, string $field, int $max): ?string
    {
        if (!property_exists($object, $field) || $object->{$field} === null) { return null; }
        if (!is_string($object->{$field})) { throw new InvalidArgumentException("Invalid provider text."); }
        $value = trim($object->{$field});
        if ($value === "") { return null; }
        if (!mb_check_encoding($value, "UTF-8") || mb_strlen($value, "UTF-8") > $max) {
            throw new InvalidArgumentException("Provider text outside bounds.");
        }
        return $value;
    }

    /** @return list<string> */
    private function strings(stdClass $object, string $field, int $maxValues, int $maxLength): array
    {
        if (!property_exists($object, $field) || $object->{$field} === null) { return []; }
        if (!is_array($object->{$field}) || count($object->{$field}) > $maxValues) {
            throw new InvalidArgumentException("Provider list outside bounds.");
        }
        $values = [];
        foreach ($object->{$field} as $raw) {
            if (!is_string($raw)) { throw new InvalidArgumentException("Invalid provider list."); }
            $value = trim($raw);
            if ($value === "" || !mb_check_encoding($value, "UTF-8")
                || mb_strlen($value, "UTF-8") > $maxLength) {
                throw new InvalidArgumentException("Provider list value outside bounds.");
            }
            if (!in_array($value, $values, true)) { $values[] = $value; }
        }
        return $values;
    }

    private function optionalInteger(stdClass $object, string $field): ?int
    {
        if (!property_exists($object, $field) || $object->{$field} === null) { return null; }
        if (!is_int($object->{$field}) || $object->{$field} < 1 || $object->{$field} > 100000) {
            throw new InvalidArgumentException("Provider integer outside bounds.");
        }
        return $object->{$field};
    }
}
