<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Metadata\GoogleBooks;

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

final readonly class GoogleBooksTextDiscoveryProvider implements
    BibliographicTextDiscoveryProvider
{
    private const string PROVIDER_KEY = "google_books";
    private const int MAXIMUM_RESULTS = 10;
    private const int MAXIMUM_RESPONSE_BYTES = 262144;

    public function __construct(
        private ProviderHttpClient $http,
        private MetadataClock $clock,
        private IsbnCanonicalizer $canonicalizer,
        private GoogleBooksConfiguration $configuration
    ) {
    }

    public function key(): string { return self::PROVIDER_KEY; }

    public function search(
        BibliographicTextQuery $query,
        BibliographicDiscoveryQuery $identity
    ): BibliographicProviderDiscoveryResult {
        $key = $this->configuration->apiKey();
        if ($key === null) {
            return BibliographicProviderDiscoveryResult::failure(
                ProviderLookupStatus::ConfigurationError,
                ProviderFailureReason::Configuration
            );
        }
        $request = new ProviderHttpRequest(
            "https://www.googleapis.com/books/v1/volumes?" . http_build_query([
                "q" => $query->value(),
                "maxResults" => self::MAXIMUM_RESULTS,
                "orderBy" => "relevance",
                "printType" => "books",
                "key" => $key,
            ], "", "&", PHP_QUERY_RFC3986),
            ["Accept" => "application/json"],
            4.0,
            self::MAXIMUM_RESPONSE_BYTES
        );
        $result = $this->http->get($request);
        if ($result->status() === ProviderHttpResultStatus::Timeout) {
            return $this->failure(ProviderLookupStatus::Unavailable, ProviderFailureReason::Timeout);
        }
        if ($result->status() === ProviderHttpResultStatus::NetworkFailure) {
            return $this->failure(ProviderLookupStatus::Unavailable, ProviderFailureReason::Network);
        }
        $response = $result->requireResponse();
        if ($response->statusCode() === 429) {
            return $this->failure(ProviderLookupStatus::RateLimited, ProviderFailureReason::RateLimited);
        }
        if ($response->statusCode() >= 500) {
            return $this->failure(ProviderLookupStatus::Unavailable, ProviderFailureReason::Http5xx);
        }
        if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
            return $this->failure(ProviderLookupStatus::Unavailable, ProviderFailureReason::HttpError);
        }

        try {
            if (strlen($response->body()) > self::MAXIMUM_RESPONSE_BYTES) {
                throw new InvalidArgumentException("Response too large.");
            }
            $payload = json_decode($response->body(), false, 16, JSON_THROW_ON_ERROR);
            if (!$payload instanceof stdClass || !isset($payload->totalItems)
                || !is_int($payload->totalItems) || $payload->totalItems < 0) {
                throw new InvalidArgumentException("Invalid Google Books response.");
            }
            if ($payload->totalItems === 0) { return BibliographicProviderDiscoveryResult::miss(); }
            if (!isset($payload->items) || !is_array($payload->items)
                || $payload->items === [] || count($payload->items) > self::MAXIMUM_RESULTS) {
                throw new InvalidArgumentException("Invalid Google Books items.");
            }
            $candidates = [];
            foreach ($payload->items as $order => $volume) {
                if (!$volume instanceof stdClass || !isset($volume->volumeInfo)
                    || !$volume->volumeInfo instanceof stdClass) {
                    throw new InvalidArgumentException("Invalid Google Books Volume.");
                }
                $publisher = $this->optionalString($volume->volumeInfo, "publisher", 255);
                $language = $this->optionalString($volume->volumeInfo, "language", 16);
                $candidates[] = BibliographicDiscoveryCandidate::external(
                    BibliographicCandidateType::ExternalEdition,
                    self::PROVIDER_KEY,
                    $this->recordId($volume),
                    null,
                    $this->clock->now(),
                    MetadataMatchMethod::TextSearch,
                    $identity,
                    $this->requiredString($volume->volumeInfo, "title", 512),
                    $this->isbn($volume->volumeInfo),
                    $this->optionalString($volume->volumeInfo, "subtitle", 512),
                    $this->strings($volume->volumeInfo, "authors", 32, 255),
                    $language === null ? [] : [$language],
                    $publisher === null ? [] : [$publisher],
                    $this->optionalString($volume->volumeInfo, "publishedDate", 64),
                    $this->optionalInteger($volume->volumeInfo, "pageCount"),
                    null,
                    $order
                );
            }
            return BibliographicProviderDiscoveryResult::candidates($candidates);
        } catch (JsonException|InvalidArgumentException) {
            return $this->failure(ProviderLookupStatus::InvalidResponse, ProviderFailureReason::Malformed);
        }
    }

    private function isbn(stdClass $info): ?CanonicalIsbnIdentity
    {
        if (!property_exists($info, "industryIdentifiers") || $info->industryIdentifiers === null) { return null; }
        if (!is_array($info->industryIdentifiers) || count($info->industryIdentifiers) > 16) {
            throw new InvalidArgumentException("Invalid identifiers.");
        }
        $identity = null;
        foreach ($info->industryIdentifiers as $identifier) {
            if (!$identifier instanceof stdClass) { throw new InvalidArgumentException("Invalid identifier."); }
            $type = $this->optionalString($identifier, "type", 32);
            if ($type !== "ISBN_10" && $type !== "ISBN_13") { continue; }
            $value = $this->requiredString($identifier, "identifier", 32);
            $parsed = $this->canonicalizer->parse($value);
            if (!$parsed->isValid() || $parsed->identity() === null) { return null; }
            if ($identity !== null && $identity->isbn13()->value()
                !== $parsed->identity()->isbn13()->value()) { return null; }
            $identity = $parsed->identity();
        }
        return $identity;
    }

    private function recordId(stdClass $volume): string
    {
        $id = $this->requiredString($volume, "id", 64);
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) !== 1) {
            throw new InvalidArgumentException("Invalid Volume ID.");
        }
        return $id;
    }

    private function failure(ProviderLookupStatus $status, ProviderFailureReason $reason): BibliographicProviderDiscoveryResult
    {
        return BibliographicProviderDiscoveryResult::failure($status, $reason);
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
