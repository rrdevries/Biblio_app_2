<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderHttpClient;
use Biblio\Core\Application\Metadata\ProviderHttpRequest;
use Biblio\Core\Application\Metadata\ProviderHttpResponse;
use Biblio\Core\Application\Metadata\ProviderHttpResult;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\Metadata\Search\BibliographicSearchProviderFailure;
use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchQuery;
use Biblio\Core\Infrastructure\Metadata\OpenLibrary\OpenLibraryBibliographicSearchProvider;
use Biblio\Core\Infrastructure\Metadata\OpenLibrary\OpenLibraryConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenLibraryBibliographicSearchProviderTest extends TestCase
{
    public function testDeterministicAuthorAndWorkFixturesMapOnlyAllowlistedFields(): void
    {
        $http = new SearchQueueHttpClient([
            ProviderHttpResult::response(new ProviderHttpResponse(
                200,
                $this->fixture("author-search-page.json")
            )),
            ProviderHttpResult::response(new ProviderHttpResponse(
                200,
                $this->fixture("work-search-page.json")
            )),
        ]);
        $provider = $this->provider($http);

        $authors = $provider->searchAuthors(new BibliographicTextSearchQuery("Rowling"));
        $works = $provider->searchWorks(new BibliographicTextSearchQuery("Harry Potter"));

        self::assertSame(["J. K. Rowling", "Joanne Rowling"], array_map(
            static fn ($item): string => $item->displayName(),
            $authors->items()
        ));
        self::assertSame(
            "Harry Potter and the Philosopher's Stone",
            $authors->items()[0]->disambiguation()->representativeWorkTitle()
        );
        self::assertSame(1965, $authors->items()[0]->disambiguation()->birthYear());
        self::assertNull($authors->items()[0]->disambiguation()->linkedWorkCount());
        self::assertNull($authors->items()[1]->disambiguation()->representativeWorkTitle());
        self::assertNull($authors->items()[1]->disambiguation()->birthYear());
        self::assertSame([
            "/works/OL82563W",
            "/works/OL17930368W",
        ], array_map(
            static fn ($item): ?string =>
                $item->reference()->providerIdentity()?->providerRecordId(),
            $works->items()
        ));
        self::assertSame([], $works->items()[0]->series());
        self::assertSame([6.0, 6.0], array_map(
            static fn (ProviderHttpRequest $request): float => $request->timeoutSeconds(),
            $http->requests()
        ));
    }

    public function testAuthorSearchUsesStrongIdentityAndIndependentOffsetPagination(): void
    {
        $documents = [];
        for ($position = 1; $position <= 10; $position++) {
            $documents[] = ["key" => "OL{$position}A", "name" => "Author {$position}"];
        }
        $http = new SearchQueueHttpClient([$this->response([
            "numFound" => 12,
            "start" => 0,
            "docs" => $documents,
        ]), $this->response([
            "numFound" => 12,
            "start" => 10,
            "docs" => [
                ["key" => "OL11A", "name" => "Author 11"],
                ["key" => "OL12A", "name" => "Author 12"],
            ],
        ])]);
        $provider = $this->provider($http);
        $query = new BibliographicTextSearchQuery("author search");

        $first = $provider->searchAuthors($query);
        $second = $provider->searchAuthors($query, $first->nextOffset() ?? 0);

        self::assertCount(10, $first->items());
        self::assertCount(2, $second->items());
        self::assertNull($second->nextOffset());
        self::assertSame(
            "/authors/OL1A",
            $first->items()[0]->reference()->providerIdentity()?->providerRecordId()
        );
        self::assertStringContainsString("/search/authors.json?", $http->requests()[0]->url());
        self::assertStringContainsString("offset=10", $http->requests()[1]->url());
    }

    public function testAuthorSearchUsesRequestedBoundedCapacityAndSourceOffset(): void
    {
        $http = new SearchQueueHttpClient([$this->response([
            "numFound" => 5,
            "start" => 0,
            "docs" => [
                ["key" => "OL101A", "name" => "First Author"],
                ["key" => "OL102A", "name" => "Second Author"],
                ["key" => "OL103A", "name" => "Third Author"],
            ],
        ])]);

        $page = $this->provider($http)->searchAuthors(
            new BibliographicTextSearchQuery("author"),
            0,
            3
        );

        self::assertCount(3, $page->items());
        self::assertSame(3, $page->nextOffset());
        self::assertCount(1, $http->requests());
        self::assertStringContainsString("limit=3", $http->requests()[0]->url());
        self::assertStringContainsString("offset=0", $http->requests()[0]->url());
        $parameters = [];
        parse_str((string) parse_url($http->requests()[0]->url(), PHP_URL_QUERY), $parameters);
        self::assertSame([
            "q" => "author",
            "fields" => "key,name,top_work,birth_date",
            "limit" => "3",
            "offset" => "0",
        ], $parameters);
        self::assertStringNotContainsString("/works", $http->requests()[0]->url());
    }

    /** @param array<string,mixed> $context */
    #[DataProvider("authorContextValues")]
    public function testAuthorOptionalContextIsNormalizedWithoutInvalidatingTheAuthor(
        array $context,
        ?string $expectedTitle,
        ?int $expectedYear
    ): void {
        $http = new SearchQueueHttpClient([$this->response([
            "numFound" => 1,
            "start" => 0,
            "docs" => [[
                "key" => "OL19981A",
                "name" => "Stephen King",
                ...$context,
            ]],
        ])]);

        $page = $this->provider($http)->searchAuthors(
            new BibliographicTextSearchQuery("stephen king")
        );

        self::assertCount(1, $page->items());
        self::assertSame($expectedTitle, $page->items()[0]->disambiguation()->representativeWorkTitle());
        self::assertSame($expectedYear, $page->items()[0]->disambiguation()->birthYear());
        self::assertNull($page->items()[0]->disambiguation()->linkedWorkCount());
        self::assertCount(1, $http->requests());
    }

    /** @return iterable<string,array{array<string,mixed>,?string,?int}> */
    public static function authorContextValues(): iterable
    {
        $currentYear = (int) gmdate("Y");

        yield "plain year and trimmed Work" => [[
            "top_work" => "  The Green Mile  ",
            "birth_date" => "1947",
        ], "The Green Mile", 1947];
        yield "month first full date" => [[
            "top_work" => "It",
            "birth_date" => "September 21, 1947",
        ], "It", 1947];
        yield "day first full date" => [[
            "birth_date" => "21 September 1947",
        ], null, 1947];
        yield "lower year boundary" => [[
            "birth_date" => "1000",
        ], null, 1000];
        yield "current year boundary" => [[
            "birth_date" => (string) $currentYear,
        ], null, $currentYear];
        yield "missing optional fields" => [[], null, null];
        yield "empty Work and malformed date" => [[
            "top_work" => " \t ",
            "birth_date" => "not a date",
        ], null, null];
        yield "non-string optional values" => [[
            "top_work" => ["It"],
            "birth_date" => 1947,
        ], null, null];
        yield "overlong Work title" => [[
            "top_work" => str_repeat("W", 513),
        ], null, null];
        yield "conflicting years" => [[
            "birth_date" => "1947-1948",
        ], null, null];
        yield "impossible ancient year" => [[
            "birth_date" => "0000",
        ], null, null];
        yield "impossible future year" => [[
            "birth_date" => "9999",
        ], null, null];
        yield "uncertain year" => [[
            "birth_date" => "circa 1947",
        ], null, null];
        yield "open range" => [[
            "birth_date" => "1947-",
        ], null, null];
    }

    public function testWorkSearchUsesOneRequestAndNeverCallsEditions(): void
    {
        $http = new SearchQueueHttpClient([$this->response([
            "num_found" => 1,
            "start" => 0,
            "docs" => [[
                "key" => "OL123W",
                "title" => "The Dispossessed",
                "author_name" => ["Ursula K. Le Guin"],
                "isbn" => ["9780000000000"],
            ]],
        ])]);

        $page = $this->provider($http)->searchWorks(
            new BibliographicTextSearchQuery("The Dispossessed")
        );

        self::assertCount(1, $page->items());
        self::assertSame("The Dispossessed", $page->items()[0]->title());
        self::assertSame(["Ursula K. Le Guin"], array_map(
            static fn ($author): string => $author->displayName(),
            $page->items()[0]->authors()
        ));
        self::assertSame([], $page->items()[0]->series());
        self::assertCount(1, $http->requests());
        self::assertStringNotContainsString("editions", $http->requests()[0]->url());
        self::assertStringContainsString("fields=key%2Ctitle%2Cauthor_name", $http->requests()[0]->url());
    }

    public function testWorkSearchMapsItsOwnCursorToProviderOffset(): void
    {
        $documents = [];
        for ($position = 1; $position <= 10; $position++) {
            $documents[] = [
                "key" => "OL{$position}W",
                "title" => "Work {$position}",
                "author_name" => ["Author {$position}"],
            ];
        }
        $http = new SearchQueueHttpClient([$this->response([
            "numFound" => 11,
            "start" => 0,
            "docs" => $documents,
        ]), $this->response([
            "numFound" => 11,
            "start" => 10,
            "docs" => [[
                "key" => "OL11W",
                "title" => "Work 11",
                "author_name" => ["Author 11"],
            ]],
        ])]);
        $provider = $this->provider($http);
        $query = new BibliographicTextSearchQuery("Work search");

        $first = $provider->searchWorks($query);
        $second = $provider->searchWorks($query, $first->nextCursor());

        self::assertCount(10, $first->items());
        self::assertCount(1, $second->items());
        self::assertNull($second->nextCursor());
        self::assertStringContainsString("offset=10", $http->requests()[1]->url());
    }

    public function testNormalMissIsAnEmptyPage(): void
    {
        $http = new SearchQueueHttpClient([$this->response([
            "numFound" => 0,
            "start" => 0,
            "docs" => [],
        ])]);

        $page = $this->provider($http)->searchAuthors(
            new BibliographicTextSearchQuery("nobody")
        );

        self::assertSame([], $page->items());
        self::assertNull($page->nextOffset());
    }

    public function testMalformedFixturesFailClosedForBothEntityGroups(): void
    {
        $provider = $this->provider(new SearchQueueHttpClient([
            ProviderHttpResult::response(new ProviderHttpResponse(
                200,
                $this->fixture("author-search-malformed.json")
            )),
        ]));
        try {
            $provider->searchAuthors(new BibliographicTextSearchQuery("malformed Author"));
            self::fail("Malformed Author fixture was accepted.");
        } catch (BibliographicSearchProviderFailure $failure) {
            self::assertSame(ProviderLookupStatus::InvalidResponse, $failure->status());
        }

        $provider = $this->provider(new SearchQueueHttpClient([
            ProviderHttpResult::response(new ProviderHttpResponse(
                200,
                $this->fixture("work-search-malformed.json")
            )),
        ]));
        try {
            $provider->searchWorks(new BibliographicTextSearchQuery("malformed Work"));
            self::fail("Malformed Work fixture was accepted.");
        } catch (BibliographicSearchProviderFailure $failure) {
            self::assertSame(ProviderLookupStatus::InvalidResponse, $failure->status());
        }
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
    }

    #[DataProvider("failures")]
    public function testTypedProviderFailures(
        ProviderHttpResult $httpResult,
        ProviderLookupStatus $status,
        ProviderFailureReason $reason
    ): void {
        try {
            $this->provider(new SearchQueueHttpClient([$httpResult]))->searchWorks(
                new BibliographicTextSearchQuery("failure")
            );
            self::fail("Provider failure was reported as a normal result.");
        } catch (BibliographicSearchProviderFailure $failure) {
            self::assertSame($status, $failure->status());
            self::assertSame($reason, $failure->reason());
        }
    }

    #[DataProvider("malformedPayloads")]
    public function testMalformedAuthorAndWorkResponsesFailClosed(
        string $group,
        array $payload
    ): void {
        $provider = $this->provider(new SearchQueueHttpClient([$this->response($payload)]));
        try {
            $group === "authors"
                ? $provider->searchAuthors(new BibliographicTextSearchQuery("malformed"))
                : $provider->searchWorks(new BibliographicTextSearchQuery("malformed"));
            self::fail("Malformed provider response was reported as a miss.");
        } catch (BibliographicSearchProviderFailure $failure) {
            self::assertSame(ProviderLookupStatus::InvalidResponse, $failure->status());
            self::assertSame(ProviderFailureReason::Malformed, $failure->reason());
        }
    }

    /** @return iterable<string,array{string,array<string,mixed>}> */
    public static function malformedPayloads(): iterable
    {
        yield "Author without identity" => ["authors", [
            "numFound" => 1,
            "start" => 0,
            "docs" => [["name" => "Nameless identity"]],
        ]];
        yield "Author invalid identity" => ["authors", [
            "numFound" => 1,
            "start" => 0,
            "docs" => [["key" => "not-an-author", "name" => "Invalid"]],
        ]];
        yield "Work without identity" => ["works", [
            "numFound" => 1,
            "start" => 0,
            "docs" => [["title" => "No key"]],
        ]];
        yield "Work document not a list" => ["works", [
            "numFound" => 1,
            "start" => 0,
            "docs" => "invalid",
        ]];
    }

    /** @param array<string,mixed> $payload */
    private function response(array $payload): ProviderHttpResult
    {
        return ProviderHttpResult::response(new ProviderHttpResponse(
            200,
            json_encode($payload, JSON_THROW_ON_ERROR)
        ));
    }

    private function provider(SearchQueueHttpClient $http): OpenLibraryBibliographicSearchProvider
    {
        return new OpenLibraryBibliographicSearchProvider(
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

final class SearchQueueHttpClient implements ProviderHttpClient
{
    /** @var list<ProviderHttpRequest> */
    private array $requests = [];

    /** @param list<ProviderHttpResult> $results */
    public function __construct(private array $results) {}

    public function get(ProviderHttpRequest $request): ProviderHttpResult
    {
        $this->requests[] = $request;
        return array_shift($this->results)
            ?? throw new \RuntimeException("Unexpected provider request.");
    }

    /** @return list<ProviderHttpRequest> */
    public function requests(): array { return $this->requests; }
}
