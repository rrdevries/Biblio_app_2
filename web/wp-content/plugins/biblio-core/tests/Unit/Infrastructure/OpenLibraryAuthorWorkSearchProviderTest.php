<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Metadata\{
    ProviderFailureReason,
    ProviderHttpClient,
    ProviderHttpRequest,
    ProviderHttpResponse,
    ProviderHttpResult,
    ProviderLookupStatus
};
use Biblio\Core\Application\Metadata\Search\{
    BibliographicAuthorReference,
    BibliographicProviderEntityIdentity,
    BibliographicSearchProviderFailure
};
use Biblio\Core\Infrastructure\Metadata\OpenLibrary\{
    OpenLibraryAuthorWorkSearchProvider,
    OpenLibraryConfiguration
};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenLibraryAuthorWorkSearchProviderTest extends TestCase
{
    public function testMapsExactAuthorWorksPageAndIgnoresEditionFields(): void
    {
        $http = new AuthorWorksQueueHttpClient([
            ProviderHttpResult::response(new ProviderHttpResponse(
                200,
                $this->fixture("author-works-page.json")
            )),
        ]);

        $page = $this->provider($http)->searchWorksForAuthor($this->author(), 0, 2);

        self::assertSame(["Alpha", "Beta"], array_map(
            static fn ($item): string => $item->title(),
            $page->items()
        ));
        self::assertSame(["/works/OL10W", "/works/OL11W"], array_map(
            static fn ($item): ?string => $item->reference()->providerIdentity()?->providerRecordId(),
            $page->items()
        ));
        self::assertSame([], $page->items()[0]->authors());
        self::assertSame([], $page->items()[0]->series());
        self::assertSame(2, $page->nextOffset());
        self::assertCount(1, $http->requests());
        self::assertSame(
            "https://openlibrary.org/authors/OL1A/works.json?limit=2&offset=0",
            $http->requests()[0]->url()
        );
        self::assertStringNotContainsString("/search", $http->requests()[0]->url());
        self::assertStringNotContainsString("editions", $http->requests()[0]->url());
        self::assertStringNotContainsString("/works/OL10W.json", $http->requests()[0]->url());
    }

    public function testPaginationUsesOnlyExactProviderOffset(): void
    {
        $http = new AuthorWorksQueueHttpClient([
            $this->response([
                "links" => [
                    "author" => "/authors/OL1A",
                    "next" => "/authors/OL1A/works.json?limit=1&offset=1",
                ],
                "size" => 2,
                "entries" => [$this->entry("/works/OL20W", "First")],
            ]),
            $this->response([
                "links" => ["author" => "/authors/OL1A"],
                "size" => 2,
                "entries" => [$this->entry("/works/OL21W", "Second")],
            ]),
        ]);
        $provider = $this->provider($http);

        $first = $provider->searchWorksForAuthor($this->author(), 0, 1);
        $second = $provider->searchWorksForAuthor(
            $this->author(),
            $first->nextOffset() ?? self::fail("Expected continuation."),
            1
        );

        self::assertSame(1, $first->nextOffset());
        self::assertNull($second->nextOffset());
        self::assertCount(2, $http->requests());
        self::assertSame(
            "https://openlibrary.org/authors/OL1A/works.json?limit=1&offset=1",
            $http->requests()[1]->url()
        );
    }

    public function testMalformedRecordIsRejectedWhileValidSiblingRemains(): void
    {
        $page = $this->provider(new AuthorWorksQueueHttpClient([$this->response([
            "links" => ["author" => "/authors/OL1A"],
            "size" => 2,
            "entries" => [
                ["key" => "invalid", "title" => "Bad", "authors" => []],
                $this->entry("/works/OL30W", "Valid"),
            ],
        ])]))->searchWorksForAuthor($this->author(), 0, 10);

        self::assertCount(1, $page->items());
        self::assertSame("Valid", $page->items()[0]->title());
    }

    public function testEntirelyUnusableOrWrongAuthorPageIsMalformedFailure(): void
    {
        foreach ([
            [["key" => "invalid", "title" => "Bad", "authors" => []]],
            [$this->entry("/works/OL31W", "Wrong Author", "/authors/OL9A")],
        ] as $entries) {
            try {
                $this->provider(new AuthorWorksQueueHttpClient([$this->response([
                    "links" => ["author" => "/authors/OL1A"],
                    "size" => 1,
                    "entries" => $entries,
                ])]))->searchWorksForAuthor($this->author(), 0, 10);
                self::fail("Unusable provider page was accepted.");
            } catch (BibliographicSearchProviderFailure $failure) {
                self::assertSame(ProviderLookupStatus::InvalidResponse, $failure->status());
                self::assertSame(ProviderFailureReason::Malformed, $failure->reason());
            }
        }
    }

    public function testValidEmptyPageIsNormalMissShape(): void
    {
        $page = $this->provider(new AuthorWorksQueueHttpClient([$this->response([
            "links" => ["author" => "/authors/OL1A"],
            "size" => 0,
            "entries" => [],
        ])]))->searchWorksForAuthor($this->author(), 0, 10);

        self::assertSame([], $page->items());
        self::assertNull($page->nextOffset());
    }

    /** @return iterable<string,array{ProviderHttpResult,ProviderLookupStatus,ProviderFailureReason}> */
    public static function failures(): iterable
    {
        yield "timeout" => [
            ProviderHttpResult::timeout(),
            ProviderLookupStatus::Unavailable,
            ProviderFailureReason::Timeout,
        ];
        yield "network" => [
            ProviderHttpResult::networkFailure(),
            ProviderLookupStatus::Unavailable,
            ProviderFailureReason::Network,
        ];
        yield "rate limit" => [
            ProviderHttpResult::response(new ProviderHttpResponse(429, "{}")),
            ProviderLookupStatus::RateLimited,
            ProviderFailureReason::RateLimited,
        ];
        yield "server" => [
            ProviderHttpResult::response(new ProviderHttpResponse(503, "{}")),
            ProviderLookupStatus::Unavailable,
            ProviderFailureReason::Http5xx,
        ];
        yield "http" => [
            ProviderHttpResult::response(new ProviderHttpResponse(404, "{}")),
            ProviderLookupStatus::Unavailable,
            ProviderFailureReason::HttpError,
        ];
    }

    #[DataProvider("failures")]
    public function testTypedProviderFailures(
        ProviderHttpResult $httpResult,
        ProviderLookupStatus $status,
        ProviderFailureReason $reason
    ): void {
        try {
            $this->provider(new AuthorWorksQueueHttpClient([$httpResult]))
                ->searchWorksForAuthor($this->author(), 0, 10);
            self::fail("Provider failure was reported as a normal result.");
        } catch (BibliographicSearchProviderFailure $failure) {
            self::assertSame($status, $failure->status());
            self::assertSame($reason, $failure->reason());
        }
    }

    private function author(): BibliographicAuthorReference
    {
        return BibliographicAuthorReference::external(
            BibliographicProviderEntityIdentity::author("open_library", "/authors/OL1A")
        );
    }

    /** @return array<string,mixed> */
    private function entry(
        string $key,
        string $title,
        string $author = "/authors/OL1A"
    ): array {
        return [
            "key" => $key,
            "title" => $title,
            "authors" => [["author" => ["key" => $author]]],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function response(array $payload): ProviderHttpResult
    {
        return ProviderHttpResult::response(new ProviderHttpResponse(
            200,
            json_encode($payload, JSON_THROW_ON_ERROR)
        ));
    }

    private function provider(
        AuthorWorksQueueHttpClient $http
    ): OpenLibraryAuthorWorkSearchProvider {
        return new OpenLibraryAuthorWorkSearchProvider(
            $http,
            new OpenLibraryConfiguration("Biblio", "2.001", "metadata@example.test")
        );
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . "/Fixtures/OpenLibrary/{$name}");
        self::assertIsString($contents);
        return $contents;
    }
}

final class AuthorWorksQueueHttpClient implements ProviderHttpClient
{
    /** @var list<ProviderHttpRequest> */ private array $requests = [];

    /** @param list<ProviderHttpResult> $results */
    public function __construct(private array $results) {}

    public function get(ProviderHttpRequest $request): ProviderHttpResult
    {
        $this->requests[] = $request;
        return array_shift($this->results)
            ?? throw new \RuntimeException("Unexpected provider request.");
    }

    /** @return list<ProviderHttpRequest> */ public function requests(): array { return $this->requests; }
}
