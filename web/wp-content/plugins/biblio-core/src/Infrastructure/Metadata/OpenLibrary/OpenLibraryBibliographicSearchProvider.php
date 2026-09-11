<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Metadata\OpenLibrary;

use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderHttpClient;
use Biblio\Core\Application\Metadata\ProviderHttpRequest;
use Biblio\Core\Application\Metadata\ProviderHttpResultStatus;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorReference;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchPage;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchProvider;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchResult;
use Biblio\Core\Application\Metadata\Search\BibliographicProviderEntityIdentity;
use Biblio\Core\Application\Metadata\Search\BibliographicSearchCursor;
use Biblio\Core\Application\Metadata\Search\BibliographicSearchProviderFailure;
use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchQuery;
use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchService;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkAuthor;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkReference;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSearchPage;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSearchProvider;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSearchResult;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class OpenLibraryBibliographicSearchProvider implements
    BibliographicAuthorSearchProvider,
    BibliographicWorkSearchProvider
{
    private const string PROVIDER_KEY = "open_library";
    private const int MAXIMUM_RESPONSE_BYTES = 262144;

    public function __construct(
        private ProviderHttpClient $http,
        private OpenLibraryConfiguration $configuration
    ) {
    }

    public function key(): string { return self::PROVIDER_KEY; }

    public function searchAuthors(
        BibliographicTextSearchQuery $query,
        ?BibliographicSearchCursor $cursor = null
    ): BibliographicAuthorSearchPage {
        $offset = $this->offset($cursor);
        $payload = $this->request("https://openlibrary.org/search/authors.json?" . http_build_query([
            "q" => $query->value(),
            "limit" => BibliographicTextSearchService::PAGE_SIZE,
            "offset" => $offset,
        ], "", "&", PHP_QUERY_RFC3986));

        try {
            [$found, $documents] = $this->searchPayload($payload, $offset);
            $items = [];
            foreach ($documents as $position => $document) {
                if (!$document instanceof stdClass) {
                    throw new InvalidArgumentException("Invalid Author search document.");
                }
                $key = $this->authorKey($this->requiredString($document, "key", 64));
                $items[] = new BibliographicAuthorSearchResult(
                    BibliographicAuthorReference::external(
                        BibliographicProviderEntityIdentity::author(self::PROVIDER_KEY, $key)
                    ),
                    $this->requiredString($document, "name", 255),
                    $offset + $position
                );
            }
            $last = $items === [] ? null : $items[array_key_last($items)];
            $hasMore = $last !== null && $found > $offset + count($documents);
            return new BibliographicAuthorSearchPage(
                $query,
                $items,
                $hasMore ? $last->cursor($query) : null
            );
        } catch (JsonException|InvalidArgumentException) {
            throw $this->malformed();
        }
    }

    public function searchWorks(
        BibliographicTextSearchQuery $query,
        ?BibliographicSearchCursor $cursor = null
    ): BibliographicWorkSearchPage {
        $offset = $this->offset($cursor);
        $payload = $this->request("https://openlibrary.org/search.json?" . http_build_query([
            "q" => $query->value(),
            "fields" => "key,title,author_name",
            "limit" => BibliographicTextSearchService::PAGE_SIZE,
            "offset" => $offset,
        ], "", "&", PHP_QUERY_RFC3986));

        try {
            [$found, $documents] = $this->searchPayload($payload, $offset);
            $items = [];
            foreach ($documents as $position => $document) {
                if (!$document instanceof stdClass) {
                    throw new InvalidArgumentException("Invalid Work search document.");
                }
                $key = $this->workKey($this->requiredString($document, "key", 64));
                $items[] = new BibliographicWorkSearchResult(
                    BibliographicWorkReference::external(
                        BibliographicProviderEntityIdentity::work(self::PROVIDER_KEY, $key)
                    ),
                    $this->requiredString($document, "title", 512),
                    array_map(
                        static fn (string $name): BibliographicWorkAuthor =>
                            new BibliographicWorkAuthor($name),
                        $this->strings($document, "author_name", 32, 255)
                    ),
                    [],
                    $offset + $position
                );
            }
            $last = $items === [] ? null : $items[array_key_last($items)];
            $hasMore = $last !== null && $found > $offset + count($documents);
            return new BibliographicWorkSearchPage(
                $query,
                $items,
                $hasMore ? $last->cursor($query) : null
            );
        } catch (JsonException|InvalidArgumentException) {
            throw $this->malformed();
        }
    }

    private function offset(?BibliographicSearchCursor $cursor): int
    {
        return $cursor === null ? 0 : $cursor->presentationOrder() + 1;
    }

    private function request(string $url): stdClass
    {
        $result = $this->http->get(new ProviderHttpRequest(
            $url,
            [
                "Accept" => "application/json",
                "User-Agent" => $this->configuration->userAgent(),
            ],
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

    /** @return array{int,list<mixed>} */
    private function searchPayload(stdClass $payload, int $offset): array
    {
        $found = $this->nonNegativeInteger($payload, "numFound", "num_found");
        $start = $this->nonNegativeInteger($payload, "start");
        if ($start !== $offset || !isset($payload->docs) || !is_array($payload->docs)
            || count($payload->docs) > BibliographicTextSearchService::PAGE_SIZE
            || ($found === 0 && $payload->docs !== [])
            || ($found > $offset && $payload->docs === [])) {
            throw new InvalidArgumentException("Invalid provider search page.");
        }
        return [$found, $payload->docs];
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

    /** @return list<string> */
    private function strings(
        stdClass $object,
        string $field,
        int $maximumValues,
        int $maximumLength
    ): array {
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
}
