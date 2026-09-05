<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Metadata\OpenLibrary;

use Biblio\Core\Application\Metadata\MetadataCandidate;
use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\MetadataProvider;
use Biblio\Core\Application\Metadata\MetadataWorkLink;
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

final readonly class OpenLibraryMetadataProvider implements MetadataProvider
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

    public function lookup(CanonicalIsbnIdentity $isbn): ProviderLookupResult
    {
        $bibKey = "ISBN:" . $isbn->isbn13()->value();
        $request = new ProviderHttpRequest(
            "https://openlibrary.org/api/books?" . http_build_query(
                ["bibkeys" => $bibKey, "jscmd" => "details", "format" => "json"],
                "",
                "&",
                PHP_QUERY_RFC3986
            ),
            [
                "Accept" => "application/json",
                "User-Agent" => $this->configuration->userAgent(),
            ],
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
            $payload = json_decode(
                $response->body(),
                false,
                16,
                JSON_THROW_ON_ERROR
            );
            if (!$payload instanceof stdClass) {
                return ProviderLookupResult::invalidResponse(
                    ProviderFailureReason::Malformed
                );
            }
            if (!property_exists($payload, $bibKey)) {
                return ProviderLookupResult::miss();
            }

            $record = $payload->{$bibKey};
            if (!$record instanceof stdClass || !isset($record->details)) {
                return ProviderLookupResult::invalidResponse(
                    ProviderFailureReason::Malformed
                );
            }
            $details = $record->details;
            if (!$details instanceof stdClass) {
                return ProviderLookupResult::invalidResponse(
                    ProviderFailureReason::Malformed
                );
            }

            $returnedIsbn = $this->returnedIsbn($details, $isbn);
            if ($returnedIsbn === null) {
                return ProviderLookupResult::invalidResponse(
                    ProviderFailureReason::IsbnMismatch
                );
            }

            return ProviderLookupResult::candidates([
                new MetadataCandidate(
                    self::PROVIDER_KEY,
                    $this->requiredRecordId($details),
                    $this->clock->now(),
                    MetadataMatchMethod::ExactIsbn,
                    $isbn,
                    $returnedIsbn,
                    $this->optionalString($details, "title", 512),
                    $this->optionalString($details, "subtitle", 512),
                    $this->contributors($details),
                    $this->languages($details),
                    $this->stringList($details, "publishers", 16, 255),
                    $this->optionalString($details, "publish_date", 64),
                    $this->optionalPositiveInteger($details, "number_of_pages"),
                    $this->optionalString($details, "physical_format", 128),
                    $this->workLink($details)
                ),
            ]);
        } catch (JsonException|InvalidArgumentException) {
            return ProviderLookupResult::invalidResponse(
                ProviderFailureReason::Malformed
            );
        }
    }

    private function returnedIsbn(
        stdClass $details,
        CanonicalIsbnIdentity $queried
    ): ?CanonicalIsbnIdentity {
        $identifiers = array_merge(
            $this->stringList($details, "isbn_13", 16, 32),
            $this->stringList($details, "isbn_10", 16, 32)
        );
        if ($identifiers === []) {
            return null;
        }

        $returned = null;
        foreach ($identifiers as $identifier) {
            $parsed = $this->canonicalizer->parse($identifier);
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

    private function requiredRecordId(stdClass $details): string
    {
        $recordId = $this->optionalString($details, "key", 64);
        if ($recordId === null || preg_match('#^/books/OL[0-9]+M$#D', $recordId) !== 1) {
            throw new InvalidArgumentException("Invalid Open Library record ID.");
        }

        return $recordId;
    }

    /** @return list<string> */
    private function contributors(stdClass $details): array
    {
        return $this->namedObjectList($details, "authors", "name", 32, 255);
    }

    /** @return list<string> */
    private function languages(stdClass $details): array
    {
        $keys = $this->namedObjectList($details, "languages", "key", 16, 64);
        $languages = [];
        foreach ($keys as $key) {
            if (preg_match('#^/languages/([a-z]{2,8})$#D', $key, $matches) !== 1) {
                throw new InvalidArgumentException("Invalid Open Library language key.");
            }
            $languages[] = $matches[1];
        }

        return $languages;
    }

    private function workLink(stdClass $details): ?MetadataWorkLink
    {
        $keys = $this->namedObjectList($details, "works", "key", 2, 64);
        if (count($keys) !== 1) {
            return null;
        }
        if (preg_match('#^/works/OL[0-9]+W$#D', $keys[0]) !== 1) {
            throw new InvalidArgumentException("Invalid Open Library Work key.");
        }

        return new MetadataWorkLink($keys[0]);
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

    /** @return list<string> */
    private function namedObjectList(
        stdClass $object,
        string $field,
        string $valueField,
        int $maximumValues,
        int $maximumLength
    ): array {
        if (!property_exists($object, $field) || $object->{$field} === null) {
            return [];
        }
        if (!is_array($object->{$field}) || count($object->{$field}) > $maximumValues) {
            throw new InvalidArgumentException("Provider object list is outside bounds.");
        }

        $values = [];
        foreach ($object->{$field} as $entry) {
            if (!$entry instanceof stdClass) {
                throw new InvalidArgumentException("Provider object list is malformed.");
            }
            $value = $this->optionalString($entry, $valueField, $maximumLength);
            if ($value !== null && !in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return $values;
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
