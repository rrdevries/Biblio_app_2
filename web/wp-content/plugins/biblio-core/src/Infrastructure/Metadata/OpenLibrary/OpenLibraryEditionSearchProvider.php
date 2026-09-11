<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Metadata\OpenLibrary;

use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderHttpClient;
use Biblio\Core\Application\Metadata\ProviderHttpRequest;
use Biblio\Core\Application\Metadata\ProviderHttpResultStatus;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\Metadata\Search\BibliographicEditionProviderPage;
use Biblio\Core\Application\Metadata\Search\BibliographicEditionReference;
use Biblio\Core\Application\Metadata\Search\BibliographicEditionSearchResult;
use Biblio\Core\Application\Metadata\Search\BibliographicExternalEditionSearchProvider;
use Biblio\Core\Application\Metadata\Search\BibliographicProviderEntityIdentity;
use Biblio\Core\Application\Metadata\Search\BibliographicProviderEntityType;
use Biblio\Core\Application\Metadata\Search\BibliographicSearchProviderFailure;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkReference;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\IsbnCanonicalizer;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class OpenLibraryEditionSearchProvider implements
    BibliographicExternalEditionSearchProvider
{
    private const string PROVIDER_KEY = "open_library";
    private const int MAXIMUM_RESPONSE_BYTES = 262144;

    public function __construct(
        private ProviderHttpClient $http,
        private MetadataClock $clock,
        private IsbnCanonicalizer $canonicalizer,
        private OpenLibraryConfiguration $configuration
    ) {
    }

    public function key(): string { return self::PROVIDER_KEY; }

    public function searchEditionsForProviderWork(
        BibliographicWorkReference $parentWork,
        BibliographicProviderEntityIdentity $providerWork,
        int $offset,
        int $limit
    ): BibliographicEditionProviderPage {
        if ($providerWork->entityType() !== BibliographicProviderEntityType::Work
            || $providerWork->providerKey() !== self::PROVIDER_KEY) {
            throw new InvalidArgumentException("Open Library Edition search requires an Open Library Work.");
        }
        if ($offset < 0 || $offset > 1000000 || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException("Open Library Edition page boundary is invalid.");
        }
        $workKey = $this->workKey($providerWork->providerRecordId());
        $payload = $this->request(
            "https://openlibrary.org{$workKey}/editions.json?" . http_build_query([
                "limit" => $limit,
                "offset" => $offset,
            ], "", "&", PHP_QUERY_RFC3986)
        );

        try {
            $size = $this->nonNegativeInteger($payload, "size");
            if (!isset($payload->links) || !$payload->links instanceof stdClass
                || $this->requiredString($payload->links, "work", 64) !== $workKey
                || !isset($payload->entries) || !is_array($payload->entries)
                || count($payload->entries) > $limit
                || ($size === 0 && $payload->entries !== [])
                || ($payload->entries !== [] && $offset + count($payload->entries) > $size)
                || ($offset < $size && $payload->entries === [])) {
                throw new InvalidArgumentException("Invalid Open Library Edition page.");
            }
            $nextOffset = $size > $offset + count($payload->entries)
                ? $offset + count($payload->entries)
                : null;
            $this->assertNextLink($payload->links, $nextOffset, $limit);

            $items = [];
            foreach ($payload->entries as $position => $entry) {
                if (!$entry instanceof stdClass) { continue; }
                try {
                    $this->assertEntryWork($entry, $workKey);
                    $editionKey = $this->editionKey($this->requiredString($entry, "key", 64));
                    $items[] = new BibliographicEditionSearchResult(
                        BibliographicEditionReference::external(
                            BibliographicProviderEntityIdentity::edition(
                                self::PROVIDER_KEY,
                                $editionKey
                            )
                        ),
                        $parentWork,
                        $this->requiredString($entry, "title", 512),
                        $this->isbn($entry),
                        $this->optionalString($entry, "subtitle", 512),
                        $this->contributors($entry),
                        $this->languageKeys($entry),
                        $this->strings($entry, "publishers", 16, 255),
                        $this->optionalString($entry, "publish_date", 64),
                        $this->optionalInteger($entry, "number_of_pages"),
                        $this->optionalString($entry, "physical_format", 128),
                        $offset + $position,
                        $this->clock->now(),
                        MetadataMatchMethod::TextSearch,
                        $providerWork
                    );
                } catch (InvalidArgumentException) {
                    // One malformed record is safely rejected; the page remains usable.
                }
            }
            if ($payload->entries !== [] && $items === []) {
                throw new InvalidArgumentException("Open Library Edition page has no valid records.");
            }
            return new BibliographicEditionProviderPage($items, $nextOffset);
        } catch (JsonException|InvalidArgumentException) {
            throw $this->malformed();
        }
    }

    private function request(string $url): stdClass
    {
        $result = $this->http->get(new ProviderHttpRequest(
            $url,
            ["Accept" => "application/json", "User-Agent" => $this->configuration->userAgent()],
            4.0,
            self::MAXIMUM_RESPONSE_BYTES
        ));
        $failure = $this->failure(
            $result->status(),
            $result->status() === ProviderHttpResultStatus::Response
                ? $result->requireResponse()->statusCode()
                : null
        );
        if ($failure !== null) { throw $failure; }
        try {
            $body = $result->requireResponse()->body();
            if (strlen($body) > self::MAXIMUM_RESPONSE_BYTES) {
                throw new InvalidArgumentException("Response too large.");
            }
            $payload = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
            if (!$payload instanceof stdClass) {
                throw new InvalidArgumentException("Response is not an object.");
            }
            return $payload;
        } catch (JsonException|InvalidArgumentException) {
            throw $this->malformed();
        }
    }

    private function assertNextLink(stdClass $links, ?int $nextOffset, int $limit): void
    {
        if ($nextOffset === null) { return; }
        $next = $this->requiredString($links, "next", 512);
        $query = parse_url($next, PHP_URL_QUERY);
        if (!is_string($query)) { throw new InvalidArgumentException("Invalid Edition next link."); }
        parse_str($query, $parameters);
        if (($parameters["offset"] ?? null) !== (string) $nextOffset
            || ($parameters["limit"] ?? null) !== (string) $limit) {
            throw new InvalidArgumentException("Invalid Edition next link.");
        }
    }

    private function assertEntryWork(stdClass $entry, string $workKey): void
    {
        if (!property_exists($entry, "works") || $entry->works === null) { return; }
        if (!is_array($entry->works) || $entry->works === []) {
            throw new InvalidArgumentException("Invalid Edition Work relation.");
        }
        $keys = [];
        foreach ($entry->works as $work) {
            if (!$work instanceof stdClass) {
                throw new InvalidArgumentException("Invalid Edition Work relation.");
            }
            $keys[] = $this->workKey($this->requiredString($work, "key", 64));
        }
        if (!in_array($workKey, $keys, true)) {
            throw new InvalidArgumentException("Edition belongs to another Work.");
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
    private function contributors(stdClass $entry): array
    {
        if (!property_exists($entry, "contributors") || $entry->contributors === null) { return []; }
        if (!is_array($entry->contributors) || count($entry->contributors) > 32) {
            throw new InvalidArgumentException("Invalid contributor list.");
        }
        $values = [];
        foreach ($entry->contributors as $contributor) {
            if (!$contributor instanceof stdClass) {
                throw new InvalidArgumentException("Invalid contributor.");
            }
            $name = $this->requiredString($contributor, "name", 255);
            if (!in_array($name, $values, true)) { $values[] = $name; }
        }
        return $values;
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

    private function failure(
        ProviderHttpResultStatus $status,
        ?int $httpStatus
    ): ?BibliographicSearchProviderFailure {
        if ($status === ProviderHttpResultStatus::Timeout) {
            return new BibliographicSearchProviderFailure(ProviderLookupStatus::Unavailable, ProviderFailureReason::Timeout);
        }
        if ($status === ProviderHttpResultStatus::NetworkFailure) {
            return new BibliographicSearchProviderFailure(ProviderLookupStatus::Unavailable, ProviderFailureReason::Network);
        }
        if ($httpStatus === 429) {
            return new BibliographicSearchProviderFailure(ProviderLookupStatus::RateLimited, ProviderFailureReason::RateLimited);
        }
        if ($httpStatus !== null && $httpStatus >= 500) {
            return new BibliographicSearchProviderFailure(ProviderLookupStatus::Unavailable, ProviderFailureReason::Http5xx);
        }
        if ($httpStatus !== null && ($httpStatus < 200 || $httpStatus >= 300)) {
            return new BibliographicSearchProviderFailure(ProviderLookupStatus::Unavailable, ProviderFailureReason::HttpError);
        }
        return null;
    }

    private function malformed(): BibliographicSearchProviderFailure
    {
        return new BibliographicSearchProviderFailure(
            ProviderLookupStatus::InvalidResponse,
            ProviderFailureReason::Malformed
        );
    }

    private function nonNegativeInteger(stdClass $object, string $field): int
    {
        if (!property_exists($object, $field) || !is_int($object->{$field}) || $object->{$field} < 0) {
            throw new InvalidArgumentException("Invalid provider count.");
        }
        return $object->{$field};
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

    private function requiredString(stdClass $object, string $field, int $maximum): string
    {
        return $this->optionalString($object, $field, $maximum)
            ?? throw new InvalidArgumentException("Missing provider text.");
    }

    private function optionalString(stdClass $object, string $field, int $maximum): ?string
    {
        if (!property_exists($object, $field) || $object->{$field} === null) { return null; }
        if (!is_string($object->{$field})) { throw new InvalidArgumentException("Invalid provider text."); }
        $value = trim($object->{$field});
        if ($value === "") { return null; }
        if (!mb_check_encoding($value, "UTF-8") || mb_strlen($value, "UTF-8") > $maximum) {
            throw new InvalidArgumentException("Provider text outside bounds.");
        }
        return $value;
    }

    /** @return list<string> */
    private function strings(stdClass $object, string $field, int $maximumValues, int $maximumLength): array
    {
        if (!property_exists($object, $field) || $object->{$field} === null) { return []; }
        if (!is_array($object->{$field}) || count($object->{$field}) > $maximumValues) {
            throw new InvalidArgumentException("Provider list outside bounds.");
        }
        $values = [];
        foreach ($object->{$field} as $raw) {
            if (!is_string($raw)) { throw new InvalidArgumentException("Invalid provider list."); }
            $value = trim($raw);
            if ($value === "" || !mb_check_encoding($value, "UTF-8")
                || mb_strlen($value, "UTF-8") > $maximumLength) {
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
