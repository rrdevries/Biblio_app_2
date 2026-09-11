<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderHttpClient;
use Biblio\Core\Application\Metadata\ProviderHttpRequest;
use Biblio\Core\Application\Metadata\ProviderHttpResponse;
use Biblio\Core\Application\Metadata\ProviderHttpResult;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\Metadata\Search\BibliographicProviderEntityIdentity;
use Biblio\Core\Application\Metadata\Search\BibliographicSearchProviderFailure;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkReference;
use Biblio\Core\Catalog\IsbnCanonicalizer;
use Biblio\Core\Infrastructure\Metadata\OpenLibrary\OpenLibraryConfiguration;
use Biblio\Core\Infrastructure\Metadata\OpenLibrary\OpenLibraryEditionSearchProvider;
use Biblio\Core\Infrastructure\Metadata\ConfigurationErrorBibliographicEditionSearchProvider;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenLibraryEditionSearchProviderTest extends TestCase
{
    public function testOneSelectedWorkIsPageableWithConcreteMetadataAndNoExtraRequests(): void
    {
        $http = new EditionSearchQueueHttpClient([
            $this->response([
                "size" => 3,
                "links" => [
                    "self" => "/works/OL1W/editions.json?limit=2&offset=0",
                    "work" => "/works/OL1W",
                    "next" => "/works/OL1W/editions.json?limit=2&offset=2",
                ],
                "entries" => [[
                    "key" => "/books/OL10M",
                    "title" => "Concrete English Edition",
                    "subtitle" => "A subtitle",
                    "languages" => [["key" => "/languages/eng"]],
                    "publishers" => ["Example Press"],
                    "publish_date" => "2001-04",
                    "isbn_10" => ["0441172717"],
                    "isbn_13" => ["9780441172719"],
                    "physical_format" => "Paperback",
                    "number_of_pages" => 320,
                    "contributors" => [["role" => "Translator", "name" => "Example Translator"]],
                    "works" => [["key" => "/works/OL1W"]],
                ], [
                    "key" => "OL11M",
                    "title" => "Uitgave zonder ISBN",
                    "languages" => [["key" => "/languages/nld"]],
                ]],
            ]),
            $this->response([
                "size" => 3,
                "links" => [
                    "self" => "/works/OL1W/editions.json?limit=2&offset=2",
                    "work" => "/works/OL1W",
                ],
                "entries" => [[
                    "key" => "/books/OL12M",
                    "title" => "Troisieme edition",
                    "languages" => [["key" => "/languages/fre"]],
                ]],
            ]),
        ]);
        $provider = $this->provider($http);
        [$parent, $identity] = $this->work();

        $first = $provider->searchEditionsForProviderWork($parent, $identity, 0, 2);
        $second = $provider->searchEditionsForProviderWork(
            $parent,
            $identity,
            $first->nextOffset() ?? -1,
            2
        );

        self::assertCount(2, $first->items());
        self::assertSame(2, $first->nextOffset());
        self::assertSame("9780441172719", $first->items()[0]->isbn()?->isbn13()->value());
        self::assertSame(["eng"], $first->items()[0]->languages());
        self::assertSame(["Example Press"], $first->items()[0]->publishers());
        self::assertSame(["Example Translator"], $first->items()[0]->contributors());
        self::assertSame("2001-04", $first->items()[0]->publicationDate());
        self::assertSame("Paperback", $first->items()[0]->format());
        self::assertSame(320, $first->items()[0]->pageCount());
        self::assertNull($first->items()[1]->isbn());
        self::assertSame(["nld"], $first->items()[1]->languages());
        self::assertTrue($first->items()[1]->canAddEditionSpecific());
        self::assertCount(1, $second->items());
        self::assertNull($second->nextOffset());
        self::assertSame(["fre"], $second->items()[0]->languages());
        self::assertCount(2, $http->requests());
        self::assertSame(
            "https://openlibrary.org/works/OL1W/editions.json?limit=2&offset=0",
            $http->requests()[0]->url()
        );
        self::assertSame(
            "https://openlibrary.org/works/OL1W/editions.json?limit=2&offset=2",
            $http->requests()[1]->url()
        );
        foreach ($http->requests() as $request) {
            self::assertStringNotContainsString("/search", $request->url());
            self::assertStringNotContainsString("/books/", $request->url());
        }
    }

    public function testOneMalformedRecordIsRejectedWithoutLosingValidSibling(): void
    {
        $http = new EditionSearchQueueHttpClient([$this->response([
            "size" => 2,
            "links" => ["work" => "/works/OL1W"],
            "entries" => [[
                "key" => "/books/OL20M",
                "title" => " ",
            ], [
                "key" => "/books/OL21M",
                "title" => "Valid sibling",
            ]],
        ])]);
        [$parent, $identity] = $this->work();

        $page = $this->provider($http)->searchEditionsForProviderWork(
            $parent,
            $identity,
            0,
            10
        );

        self::assertCount(1, $page->items());
        self::assertSame("Valid sibling", $page->items()[0]->title());
        self::assertNull($page->nextOffset());
    }

    public function testWrongPageOrRecordWorkRelationFailsClosedWhenNoValidRecordRemains(): void
    {
        $http = new EditionSearchQueueHttpClient([$this->response([
            "size" => 1,
            "links" => ["work" => "/works/OL1W"],
            "entries" => [[
                "key" => "/books/OL30M",
                "title" => "Wrong relation",
                "works" => [["key" => "/works/OL2W"]],
            ]],
        ])]);
        [$parent, $identity] = $this->work();

        try {
            $this->provider($http)->searchEditionsForProviderWork($parent, $identity, 0, 10);
            self::fail("Edition for another Work was accepted.");
        } catch (BibliographicSearchProviderFailure $failure) {
            self::assertSame(ProviderLookupStatus::InvalidResponse, $failure->status());
            self::assertSame(ProviderFailureReason::Malformed, $failure->reason());
        }
    }

    public function testNormalMissIsAnEmptyTerminalPage(): void
    {
        $http = new EditionSearchQueueHttpClient([$this->response([
            "size" => 0,
            "links" => ["work" => "/works/OL1W"],
            "entries" => [],
        ])]);
        [$parent, $identity] = $this->work();

        $page = $this->provider($http)->searchEditionsForProviderWork(
            $parent,
            $identity,
            0,
            10
        );

        self::assertSame([], $page->items());
        self::assertNull($page->nextOffset());
    }

    /** @return iterable<string,array{ProviderHttpResult,ProviderLookupStatus,ProviderFailureReason}> */
    public static function failures(): iterable
    {
        yield "timeout" => [ProviderHttpResult::timeout(), ProviderLookupStatus::Unavailable, ProviderFailureReason::Timeout];
        yield "network" => [ProviderHttpResult::networkFailure(), ProviderLookupStatus::Unavailable, ProviderFailureReason::Network];
        yield "rate limit" => [ProviderHttpResult::response(new ProviderHttpResponse(429, "{}")), ProviderLookupStatus::RateLimited, ProviderFailureReason::RateLimited];
        yield "server" => [ProviderHttpResult::response(new ProviderHttpResponse(503, "{}")), ProviderLookupStatus::Unavailable, ProviderFailureReason::Http5xx];
        yield "http" => [ProviderHttpResult::response(new ProviderHttpResponse(404, "{}")), ProviderLookupStatus::Unavailable, ProviderFailureReason::HttpError];
    }

    public function testConfigurationErrorProviderRemainsTyped(): void
    {
        [$parent, $identity] = $this->work();
        try {
            (new ConfigurationErrorBibliographicEditionSearchProvider("open_library"))
                ->searchEditionsForProviderWork($parent, $identity, 0, 10);
            self::fail("Configuration error became a normal miss.");
        } catch (BibliographicSearchProviderFailure $failure) {
            self::assertSame(ProviderLookupStatus::ConfigurationError, $failure->status());
            self::assertSame(ProviderFailureReason::Configuration, $failure->reason());
        }
    }

    #[DataProvider("failures")]
    public function testTypedProviderFailures(
        ProviderHttpResult $result,
        ProviderLookupStatus $status,
        ProviderFailureReason $reason
    ): void {
        [$parent, $identity] = $this->work();
        try {
            $this->provider(new EditionSearchQueueHttpClient([$result]))
                ->searchEditionsForProviderWork($parent, $identity, 0, 10);
            self::fail("Provider failure became a normal miss.");
        } catch (BibliographicSearchProviderFailure $failure) {
            self::assertSame($status, $failure->status());
            self::assertSame($reason, $failure->reason());
        }
    }

    /** @return array{BibliographicWorkReference,BibliographicProviderEntityIdentity} */
    private function work(): array
    {
        $identity = BibliographicProviderEntityIdentity::work("open_library", "/works/OL1W");
        return [BibliographicWorkReference::external($identity), $identity];
    }

    private function provider(EditionSearchQueueHttpClient $http): OpenLibraryEditionSearchProvider
    {
        return new OpenLibraryEditionSearchProvider(
            $http,
            new EditionSearchFixedClock(),
            new IsbnCanonicalizer(),
            new OpenLibraryConfiguration("Biblio", "2.001", "metadata@example.test")
        );
    }

    /** @param array<string,mixed> $payload */
    private function response(array $payload): ProviderHttpResult
    {
        return ProviderHttpResult::response(new ProviderHttpResponse(
            200,
            json_encode($payload, JSON_THROW_ON_ERROR)
        ));
    }
}

final class EditionSearchQueueHttpClient implements ProviderHttpClient
{
    /** @var list<ProviderHttpRequest> */ private array $requests = [];
    /** @param list<ProviderHttpResult> $results */ public function __construct(private array $results) {}
    public function get(ProviderHttpRequest $request): ProviderHttpResult
    {
        $this->requests[] = $request;
        return array_shift($this->results) ?? throw new \RuntimeException("Unexpected provider request.");
    }
    /** @return list<ProviderHttpRequest> */ public function requests(): array { return $this->requests; }
}

final readonly class EditionSearchFixedClock implements MetadataClock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable("2026-09-11T10:00:00Z"); }
}
