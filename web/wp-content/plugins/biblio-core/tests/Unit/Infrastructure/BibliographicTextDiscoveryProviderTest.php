<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Metadata\Discovery\BibliographicCandidateType;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryQuery;
use Biblio\Core\Application\Metadata\Discovery\BibliographicTextQuery;
use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Application\Metadata\ProviderHttpClient;
use Biblio\Core\Application\Metadata\ProviderHttpRequest;
use Biblio\Core\Application\Metadata\ProviderHttpResponse;
use Biblio\Core\Application\Metadata\ProviderHttpResult;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Catalog\IsbnCanonicalizer;
use Biblio\Core\Infrastructure\Metadata\GoogleBooks\GoogleBooksConfiguration;
use Biblio\Core\Infrastructure\Metadata\GoogleBooks\GoogleBooksTextDiscoveryProvider;
use Biblio\Core\Infrastructure\Metadata\OpenLibrary\OpenLibraryConfiguration;
use Biblio\Core\Infrastructure\Metadata\OpenLibrary\OpenLibraryTextDiscoveryProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BibliographicTextDiscoveryProviderTest extends TestCase
{
    public function testOpenLibraryPreservesWorkAndMultipleEditionsInProviderOrder(): void
    {
        $http = new BibliographicQueueHttpClient([
            $this->response(json_encode([
                "numFound" => 1,
                "docs" => [[
                    "key" => "/works/OL123W",
                    "title" => "The Dispossessed",
                    "author_name" => ["Ursula K. Le Guin"],
                ]],
            ], JSON_THROW_ON_ERROR)),
            $this->response(json_encode([
                "entries" => [
                    [
                        "key" => "/books/OL10M",
                        "title" => "The Dispossessed",
                        "publishers" => ["Harper"],
                        "publish_date" => "1974",
                        "isbn_13" => ["9780060512750"],
                    ],
                    [
                        "key" => "/books/OL11M",
                        "title" => "The Dispossessed",
                        "publishers" => ["Gollancz"],
                        "publish_date" => "2002",
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $text = new BibliographicTextQuery("The Dispossessed Le Guin");
        $provider = new OpenLibraryTextDiscoveryProvider(
            $http,
            new BibliographicFixedClock(),
            new IsbnCanonicalizer(),
            new OpenLibraryConfiguration("Biblio", "2.001", "metadata@example.test")
        );

        $result = $provider->search($text, BibliographicDiscoveryQuery::text($text));

        self::assertSame(ProviderLookupStatus::Candidates, $result->status());
        self::assertSame([
            BibliographicCandidateType::ExternalWork,
            BibliographicCandidateType::ExternalEdition,
            BibliographicCandidateType::ExternalEdition,
        ], array_map(static fn ($candidate) => $candidate->type(), $result->candidatesList()));
        self::assertSame([0, 1, 2], array_map(
            static fn ($candidate): int => $candidate->presentationOrder(),
            $result->candidatesList()
        ));
        self::assertSame(
            ["/works/OL123W", "/works/OL123W", "/works/OL123W"],
            array_map(static fn ($candidate): ?string => $candidate->providerWorkId(), $result->candidatesList())
        );
        self::assertTrue($result->candidatesList()[2]->canAddEditionSpecific());
        self::assertNull($result->candidatesList()[2]->isbn());
        self::assertStringContainsString("q=The%20Dispossessed%20Le%20Guin", $http->requests()[0]->url());
        self::assertSame(
            "https://openlibrary.org/works/OL123W/editions.json?limit=4",
            $http->requests()[1]->url()
        );
    }

    public function testGooglePreservesRankButDoesNotTurnItIntoIdentity(): void
    {
        $http = new BibliographicQueueHttpClient([$this->response(json_encode([
            "totalItems" => 2,
            "items" => [
                ["id" => "volume-first", "volumeInfo" => [
                    "title" => "First result",
                    "authors" => ["Author One"],
                ]],
                ["id" => "volume-second", "volumeInfo" => [
                    "title" => "Second result",
                ]],
            ],
        ], JSON_THROW_ON_ERROR))]);
        $text = new BibliographicTextQuery("shared title author query");
        $provider = new GoogleBooksTextDiscoveryProvider(
            $http,
            new BibliographicFixedClock(),
            new IsbnCanonicalizer(),
            new GoogleBooksConfiguration("test-key")
        );

        $result = $provider->search($text, BibliographicDiscoveryQuery::text($text));

        self::assertSame(ProviderLookupStatus::Candidates, $result->status());
        self::assertSame([0, 1], array_map(
            static fn ($candidate): int => $candidate->presentationOrder(),
            $result->candidatesList()
        ));
        self::assertTrue($result->candidatesList()[0]->canAddEditionSpecific());
        self::assertFalse($result->candidatesList()[1]->canAddWorkOnly());
        self::assertFalse($result->candidatesList()[1]->canAddEditionSpecific());
        self::assertNull($result->candidatesList()[0]->providerWorkId());
        self::assertStringContainsString("orderBy=relevance", $http->requests()[0]->url());
    }

    public function testMalformedResponseIsNotReportedAsNormalMiss(): void
    {
        $http = new BibliographicQueueHttpClient([
            $this->response('{"numFound":1,"docs":"not-a-list"}'),
        ]);
        $text = new BibliographicTextQuery("malformed provider");
        $provider = new OpenLibraryTextDiscoveryProvider(
            $http,
            new BibliographicFixedClock(),
            new IsbnCanonicalizer(),
            new OpenLibraryConfiguration("Biblio", "2.001", "metadata@example.test")
        );

        $result = $provider->search($text, BibliographicDiscoveryQuery::text($text));

        self::assertSame(ProviderLookupStatus::InvalidResponse, $result->status());
        self::assertSame([], $result->candidatesList());
    }

    public function testEditionSubrequestFailureFailsClosedInsteadOfHidingPartialResults(): void
    {
        $http = new BibliographicQueueHttpClient([
            $this->response(json_encode([
                "numFound" => 1,
                "docs" => [[
                    "key" => "/works/OL123W",
                    "title" => "The Dispossessed",
                    "author_name" => ["Ursula K. Le Guin"],
                ]],
            ], JSON_THROW_ON_ERROR)),
            $this->response('{"entries":"not-a-list"}'),
        ]);
        $text = new BibliographicTextQuery("The Dispossessed Le Guin");
        $provider = new OpenLibraryTextDiscoveryProvider(
            $http,
            new BibliographicFixedClock(),
            new IsbnCanonicalizer(),
            new OpenLibraryConfiguration("Biblio", "2.001", "metadata@example.test")
        );

        $result = $provider->search(
            $text,
            BibliographicDiscoveryQuery::text($text)
        );

        self::assertSame(ProviderLookupStatus::InvalidResponse, $result->status());
        self::assertSame([], $result->candidatesList());
    }

    private function response(string $body): ProviderHttpResult
    {
        return ProviderHttpResult::response(new ProviderHttpResponse(200, $body));
    }
}

final class BibliographicQueueHttpClient implements ProviderHttpClient
{
    /** @var list<ProviderHttpRequest> */
    private array $requests = [];

    /** @param list<ProviderHttpResult> $results */
    public function __construct(private array $results) {}

    public function get(ProviderHttpRequest $request): ProviderHttpResult
    {
        $this->requests[] = $request;
        return array_shift($this->results)
            ?? throw new RuntimeException("Unexpected provider request.");
    }

    /** @return list<ProviderHttpRequest> */
    public function requests(): array { return $this->requests; }
}

final class BibliographicFixedClock implements MetadataClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-10T12:00:00+00:00");
    }
}
