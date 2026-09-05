<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Metadata\GoogleBooks;

use Biblio\Core\Application\Metadata\MetadataCandidate;
use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\MetadataProvider;
use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderHttpClient;
use Biblio\Core\Application\Metadata\ProviderHttpRequest;
use Biblio\Core\Application\Metadata\ProviderHttpResultStatus;
use Biblio\Core\Application\Metadata\ProviderLookupResult;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\IsbnCanonicalizer;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class GoogleBooksMetadataProvider implements MetadataProvider
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

    public function key(): string
    {
        return self::PROVIDER_KEY;
    }

    public function lookup(CanonicalIsbnIdentity $isbn): ProviderLookupResult
    {
        $apiKey = $this->configuration->apiKey();
        if ($apiKey === null) {
            return ProviderLookupResult::configurationError();
        }

        $request = new ProviderHttpRequest(
            "https://www.googleapis.com/books/v1/volumes?" . http_build_query(
                [
                    "q" => "isbn:" . $isbn->isbn13()->value(),
                    "maxResults" => self::MAXIMUM_RESULTS,
                    "key" => $apiKey,
                ],
                "",
                "&",
                PHP_QUERY_RFC3986
            ),
            ["Accept" => "application/json"],
            4.0,
            self::MAXIMUM_RESPONSE_BYTES
        );
        $httpResult = $this->http->get($request);

        if ($httpResult->status() === ProviderHttpResultStatus::Timeout) {
            return ProviderLookupResult::unavailable(ProviderFailureReason::Timeout);
        }
        if ($httpResult->status() === ProviderHttpResultStatus::NetworkFailure) {
            return ProviderLookupResult::unavailable(ProviderFailureReason::Network);
        }

        $response = $httpResult->requireResponse();
        if ($response->statusCode() === 429) {
            return ProviderLookupResult::rateLimited();
        }
        if ($response->statusCode() >= 500) {
            return ProviderLookupResult::unavailable(ProviderFailureReason::Http5xx);
        }
        if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
            return ProviderLookupResult::unavailable(ProviderFailureReason::HttpError);
        }
        if (strlen($response->body()) > self::MAXIMUM_RESPONSE_BYTES) {
            return ProviderLookupResult::invalidResponse(ProviderFailureReason::Malformed);
        }

        try {
            $payload = json_decode($response->body(), false, 16, JSON_THROW_ON_ERROR);
            if (!$payload instanceof stdClass) {
                return ProviderLookupResult::invalidResponse(ProviderFailureReason::Malformed);
            }

            $totalItems = $this->requiredNonNegativeInteger($payload, "totalItems");
            if ($totalItems === 0) {
                return ProviderLookupResult::miss();
            }
            if (!isset($payload->items) || !is_array($payload->items)) {
                return ProviderLookupResult::invalidResponse(ProviderFailureReason::Malformed);
            }
            if ($payload->items === [] || count($payload->items) > self::MAXIMUM_RESULTS) {
                return ProviderLookupResult::invalidResponse(ProviderFailureReason::Malformed);
            }

            $candidates = [];
            foreach ($payload->items as $volume) {
                if (!$volume instanceof stdClass || !isset($volume->volumeInfo)) {
                    throw new InvalidArgumentException("Google Books Volume is malformed.");
                }
                if (!$volume->volumeInfo instanceof stdClass) {
                    throw new InvalidArgumentException("Google Books VolumeInfo is malformed.");
                }

                $returnedIsbn = $this->returnedIsbn($volume->volumeInfo, $isbn);
                if ($returnedIsbn === null) {
                    continue;
                }

                $publisher = $this->optionalString($volume->volumeInfo, "publisher", 255);
                $language = $this->optionalString($volume->volumeInfo, "language", 16);
                $candidates[] = new MetadataCandidate(
                    self::PROVIDER_KEY,
                    $this->requiredRecordId($volume),
                    $this->clock->now(),
                    MetadataMatchMethod::ExactIsbn,
                    $isbn,
                    $returnedIsbn,
                    $this->optionalString($volume->volumeInfo, "title", 512),
                    $this->optionalString($volume->volumeInfo, "subtitle", 512),
                    $this->stringList($volume->volumeInfo, "authors", 32, 255),
                    $language === null ? [] : [$language],
                    $publisher === null ? [] : [$publisher],
                    $this->optionalString($volume->volumeInfo, "publishedDate", 64),
                    $this->optionalPositiveInteger($volume->volumeInfo, "pageCount"),
                    null,
                    null
                );
            }

            return $candidates === []
                ? ProviderLookupResult::invalidResponse(ProviderFailureReason::IsbnMismatch)
                : ProviderLookupResult::candidates($candidates);
        } catch (JsonException|InvalidArgumentException) {
            return ProviderLookupResult::invalidResponse(ProviderFailureReason::Malformed);
        }
    }

    private function returnedIsbn(
        stdClass $volumeInfo,
        CanonicalIsbnIdentity $queried
    ): ?CanonicalIsbnIdentity {
        if (
            !isset($volumeInfo->industryIdentifiers)
            || !is_array($volumeInfo->industryIdentifiers)
            || count($volumeInfo->industryIdentifiers) > 16
        ) {
            return null;
        }

        $returned = null;
        foreach ($volumeInfo->industryIdentifiers as $identifier) {
            if (!$identifier instanceof stdClass) {
                throw new InvalidArgumentException("Google Books identifier is malformed.");
            }
            $type = $this->optionalString($identifier, "type", 32);
            if ($type !== "ISBN_10" && $type !== "ISBN_13") {
                continue;
            }
            $value = $this->optionalString($identifier, "identifier", 32);
            if ($value === null) {
                return null;
            }

            $parsed = $this->canonicalizer->parse($value);
            $identity = $parsed->identity();
            if (
                !$parsed->isValid()
                || $identity === null
                || $identity->isbn13()->value() !== $queried->isbn13()->value()
            ) {
                return null;
            }
            $returned = $identity;
        }

        return $returned;
    }

    private function requiredRecordId(stdClass $volume): string
    {
        $recordId = $this->optionalString($volume, "id", 64);
        if (
            $recordId === null
            || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $recordId) !== 1
        ) {
            throw new InvalidArgumentException("Invalid Google Books Volume ID.");
        }

        return $recordId;
    }

    private function optionalString(
        stdClass $object,
        string $field,
        int $maximumLength
    ): ?string {
        if (!property_exists($object, $field) || $object->{$field} === null) {
            return null;
        }
        if (!is_string($object->{$field})) {
            throw new InvalidArgumentException("Provider text has an invalid type.");
        }

        $value = trim($object->{$field});
        if ($value === "") {
            return null;
        }
        if (!mb_check_encoding($value, "UTF-8") || mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException("Provider text is outside bounds.");
        }

        return $value;
    }

    /** @return list<string> */
    private function stringList(
        stdClass $object,
        string $field,
        int $maximumValues,
        int $maximumLength
    ): array {
        if (!property_exists($object, $field) || $object->{$field} === null) {
            return [];
        }
        if (!is_array($object->{$field}) || count($object->{$field}) > $maximumValues) {
            throw new InvalidArgumentException("Provider list is outside bounds.");
        }

        $values = [];
        foreach ($object->{$field} as $rawValue) {
            if (!is_string($rawValue)) {
                throw new InvalidArgumentException("Provider list value has an invalid type.");
            }
            $value = trim($rawValue);
            if (
                $value === ""
                || !mb_check_encoding($value, "UTF-8")
                || mb_strlen($value) > $maximumLength
            ) {
                throw new InvalidArgumentException("Provider list value is outside bounds.");
            }
            if (!in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function requiredNonNegativeInteger(stdClass $object, string $field): int
    {
        if (
            !property_exists($object, $field)
            || !is_int($object->{$field})
            || $object->{$field} < 0
        ) {
            throw new InvalidArgumentException("Provider count is invalid.");
        }

        return $object->{$field};
    }

    private function optionalPositiveInteger(stdClass $object, string $field): ?int
    {
        if (!property_exists($object, $field) || $object->{$field} === null) {
            return null;
        }
        if (!is_int($object->{$field}) || $object->{$field} < 1 || $object->{$field} > 100000) {
            throw new InvalidArgumentException("Provider integer is outside bounds.");
        }

        return $object->{$field};
    }
}
