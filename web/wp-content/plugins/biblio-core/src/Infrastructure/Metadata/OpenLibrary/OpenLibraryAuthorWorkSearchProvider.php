<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Metadata\OpenLibrary;

use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderHttpClient;
use Biblio\Core\Application\Metadata\ProviderHttpRequest;
use Biblio\Core\Application\Metadata\ProviderHttpResultStatus;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorReference;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorWorkProviderPage;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorWorkSearchProvider;
use Biblio\Core\Application\Metadata\Search\BibliographicProviderEntityIdentity;
use Biblio\Core\Application\Metadata\Search\BibliographicProviderEntityType;
use Biblio\Core\Application\Metadata\Search\BibliographicSearchProviderFailure;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkReference;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSearchResult;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class OpenLibraryAuthorWorkSearchProvider implements
    BibliographicAuthorWorkSearchProvider
{
    private const string PROVIDER_KEY = "open_library";
    private const int MAXIMUM_RESPONSE_BYTES = 262144;

    public function __construct(
        private ProviderHttpClient $http,
        private OpenLibraryConfiguration $configuration
    ) {
    }

    public function key(): string { return self::PROVIDER_KEY; }

    public function searchWorksForAuthor(
        BibliographicAuthorReference $author,
        int $offset,
        int $limit
    ): BibliographicAuthorWorkProviderPage {
        $providerAuthor = $author->providerIdentity();
        if ($providerAuthor === null
            || $providerAuthor->entityType() !== BibliographicProviderEntityType::Author
            || $providerAuthor->providerKey() !== self::PROVIDER_KEY) {
            throw new InvalidArgumentException(
                "Open Library Works-by-Author requires an Open Library Author."
            );
        }
        if ($offset < 0 || $offset > 1000000 || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException("Open Library Works-by-Author page boundary is invalid.");
        }
        $authorKey = $this->authorKey($providerAuthor->providerRecordId());
        $payload = $this->request(
            "https://openlibrary.org{$authorKey}/works.json?" . http_build_query([
                "limit" => $limit,
                "offset" => $offset,
            ], "", "&", PHP_QUERY_RFC3986)
        );

        try {
            $size = $this->nonNegativeInteger($payload, "size");
            if (!isset($payload->links) || !$payload->links instanceof stdClass
                || $this->requiredString($payload->links, "author", 64) !== $authorKey
                || !isset($payload->entries) || !is_array($payload->entries)
                || count($payload->entries) > $limit
                || ($size === 0 && $payload->entries !== [])
                || ($payload->entries !== [] && $offset + count($payload->entries) > $size)
                || ($offset < $size && $payload->entries === [])) {
                throw new InvalidArgumentException("Invalid Open Library Works-by-Author page.");
            }
            $nextOffset = $size > $offset + count($payload->entries)
                ? $offset + count($payload->entries)
                : null;
            $this->assertNextLink($payload->links, $nextOffset, $limit, $authorKey);

            $items = [];
            foreach ($payload->entries as $position => $entry) {
                if (!$entry instanceof stdClass) { continue; }
                try {
                    $this->assertAuthorRelation($entry, $authorKey);
                    $workKey = $this->workKey($this->requiredString($entry, "key", 64));
                    $items[] = new BibliographicWorkSearchResult(
                        BibliographicWorkReference::external(
                            BibliographicProviderEntityIdentity::work(
                                self::PROVIDER_KEY,
                                $workKey
                            )
                        ),
                        $this->requiredString($entry, "title", 512),
                        [],
                        [],
                        $offset + $position
                    );
                } catch (InvalidArgumentException) {
                    // One malformed Work is rejected without discarding valid siblings.
                }
            }
            if ($payload->entries !== [] && $items === []) {
                throw new InvalidArgumentException(
                    "Open Library Works-by-Author page has no valid records."
                );
            }
            return new BibliographicAuthorWorkProviderPage($items, $nextOffset);
        } catch (InvalidArgumentException) {
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

    private function assertAuthorRelation(stdClass $entry, string $authorKey): void
    {
        if (!isset($entry->authors) || !is_array($entry->authors)
            || $entry->authors === [] || count($entry->authors) > 32) {
            throw new InvalidArgumentException("Invalid Work Author relation.");
        }
        $keys = [];
        foreach ($entry->authors as $relation) {
            if (!$relation instanceof stdClass || !isset($relation->author)
                || !$relation->author instanceof stdClass) {
                throw new InvalidArgumentException("Invalid Work Author relation.");
            }
            $keys[] = $this->authorKey(
                $this->requiredString($relation->author, "key", 64)
            );
        }
        if (!in_array($authorKey, $keys, true)) {
            throw new InvalidArgumentException("Work belongs to another Author.");
        }
    }

    private function assertNextLink(
        stdClass $links,
        ?int $nextOffset,
        int $limit,
        string $authorKey
    ): void {
        if ($nextOffset === null) { return; }
        $next = $this->requiredString($links, "next", 512);
        if (parse_url($next, PHP_URL_PATH) !== "{$authorKey}/works.json") {
            throw new InvalidArgumentException("Invalid Works-by-Author next link.");
        }
        $query = parse_url($next, PHP_URL_QUERY);
        if (!is_string($query)) {
            throw new InvalidArgumentException("Invalid Works-by-Author next link.");
        }
        parse_str($query, $parameters);
        if (($parameters["offset"] ?? null) !== (string) $nextOffset
            || ($parameters["limit"] ?? null) !== (string) $limit) {
            throw new InvalidArgumentException("Invalid Works-by-Author next link.");
        }
    }

    private function failure(
        ProviderHttpResultStatus $status,
        ?int $httpStatus
    ): ?BibliographicSearchProviderFailure {
        if ($status === ProviderHttpResultStatus::Timeout) {
            return new BibliographicSearchProviderFailure(
                ProviderLookupStatus::Unavailable,
                ProviderFailureReason::Timeout
            );
        }
        if ($status === ProviderHttpResultStatus::NetworkFailure) {
            return new BibliographicSearchProviderFailure(
                ProviderLookupStatus::Unavailable,
                ProviderFailureReason::Network
            );
        }
        if ($httpStatus === 429) {
            return new BibliographicSearchProviderFailure(
                ProviderLookupStatus::RateLimited,
                ProviderFailureReason::RateLimited
            );
        }
        if ($httpStatus !== null && $httpStatus >= 500) {
            return new BibliographicSearchProviderFailure(
                ProviderLookupStatus::Unavailable,
                ProviderFailureReason::Http5xx
            );
        }
        if ($httpStatus !== null && ($httpStatus < 200 || $httpStatus >= 300)) {
            return new BibliographicSearchProviderFailure(
                ProviderLookupStatus::Unavailable,
                ProviderFailureReason::HttpError
            );
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
        if (!property_exists($object, $field) || !is_int($object->{$field})
            || $object->{$field} < 0 || $object->{$field} > 1000000) {
            throw new InvalidArgumentException("Invalid provider count.");
        }
        return $object->{$field};
    }

    private function authorKey(string $value): string
    {
        $value = str_starts_with($value, "/authors/") ? $value : "/authors/" . $value;
        if (preg_match('#^/authors/OL[0-9]+A$#D', $value) !== 1) {
            throw new InvalidArgumentException("Invalid Author key.");
        }
        return $value;
    }

    private function workKey(string $value): string
    {
        $value = str_starts_with($value, "/works/") ? $value : "/works/" . $value;
        if (preg_match('#^/works/OL[0-9]+W$#D', $value) !== 1) {
            throw new InvalidArgumentException("Invalid Work key.");
        }
        return $value;
    }

    private function requiredString(stdClass $object, string $field, int $maximum): string
    {
        if (!property_exists($object, $field) || !is_string($object->{$field})) {
            throw new InvalidArgumentException("Missing provider text.");
        }
        $value = trim($object->{$field});
        if ($value === "" || !mb_check_encoding($value, "UTF-8")
            || mb_strlen($value, "UTF-8") > $maximum) {
            throw new InvalidArgumentException("Provider text outside bounds.");
        }
        return $value;
    }
}
