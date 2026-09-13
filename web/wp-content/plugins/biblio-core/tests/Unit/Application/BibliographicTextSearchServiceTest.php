<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderIdentityRepository;
use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorReference;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorMatchQuality;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorProviderIdentityLookup;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchPage;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchCursor;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchLane;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchProvider;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchResult;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchSourcePage;
use Biblio\Core\Application\Metadata\Search\BibliographicProviderEntityIdentity;
use Biblio\Core\Application\Metadata\Search\BibliographicSearchCursor;
use Biblio\Core\Application\Metadata\Search\BibliographicSearchProviderFailure;
use Biblio\Core\Application\Metadata\Search\BibliographicSearchResultKind;
use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchQuery;
use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchRequest;
use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchService;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkAuthor;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkReference;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSearchPage;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSearchProvider;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSearchResult;
use Biblio\Core\Catalog\AuthorId;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\AuthenticationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use PHPUnit\Framework\TestCase;

final class BibliographicTextSearchServiceTest extends TestCase
{
    public function testAuthorMatchQualityUsesOnlyUnicodeCaseAndWhitespace(): void
    {
        $query = new BibliographicTextSearchQuery("stephen\u{00A0}king");
        foreach (["Stephen King", "STEPHEN   KING", "stephen\tking"] as $name) {
            self::assertSame(
                BibliographicAuthorMatchQuality::Exact,
                $this->externalAuthor($query, "/authors/OL" . md5($name) . "A", $name, 0)
                    ->matchQuality()
            );
        }
        foreach (["Stephen D. King", "Stephen King-Hall", "José Saramago"] as $name) {
            self::assertSame(
                BibliographicAuthorMatchQuality::Broader,
                $this->externalAuthor($query, "/authors/OL" . md5($name) . "A", $name, 0)
                    ->matchQuality()
            );
        }

        $rowling = $this->externalAuthor(
            new BibliographicTextSearchQuery("JK Rowling"),
            "/authors/OL999A",
            "J. K. Rowling",
            0
        );
        self::assertSame(BibliographicAuthorMatchQuality::Broader, $rowling->matchQuality());
        self::assertStringStartsWith("author-name-", $rowling->nameGroupId());
    }

    public function testAuthorCompositionUsesFourTiersAndPreservesProviderOrderWithinTier(): void
    {
        $query = new BibliographicTextSearchQuery("stephen king");
        $local = new SearchFakeProvider([
            $this->localAuthor($query, "author-exact", "Stephen King", 0),
            $this->localAuthor($query, "author-broader", "Stephen King-Hall", 1),
        ], []);
        $external = new SearchFakeProvider([
            $this->externalAuthor($query, "/authors/OL1A", "Anthony Stephen King", 0),
            $this->externalAuthor($query, "/authors/OL2A", "STEPHEN KING", 1),
            $this->externalAuthor($query, "/authors/OL3A", "stephen king", 2),
            $this->externalAuthor($query, "/authors/OL4A", "Stephen D. King", 3),
        ], []);

        $items = $this->service($local, $external)->search(
            new BibliographicTextSearchRequest($query)
        )->authors()->items();

        self::assertSame([
            "author-exact",
            "author-broader",
            "/authors/OL2A",
            "/authors/OL3A",
            "/authors/OL1A",
            "/authors/OL4A",
        ], array_map(static fn (BibliographicAuthorSearchResult $item): string =>
            $item->reference()->authorId()?->value()
                ?? $item->reference()->providerIdentity()?->providerRecordId()
                ?? "",
            $items
        ));
    }

    public function testMappedExternalIsSuppressedButUnmappedSameNamesRemainAndReadsAreBatched(): void
    {
        $query = new BibliographicTextSearchQuery("stephen king");
        $local = new SearchFakeProvider([
            $this->localAuthor($query, "author-stephen", "Stephen King", 0),
        ], []);
        $external = new SearchFakeProvider([
            $this->externalAuthor($query, "/authors/OL19981A", "Stephen King", 0),
            $this->externalAuthor($query, "/authors/OL20000A", "Stephen King", 1),
            $this->externalAuthor($query, "/authors/OL20001A", "STEPHEN KING", 2),
            $this->externalAuthor($query, "/authors/OL20002A", "Stephen King", 3),
        ], []);
        $identities = new SearchIdentityRepository();
        $identities->authorMappings["/authors/OL19981A"] = new AuthorId("author-stephen");
        $identities->authorMappings["/authors/OL20002A"] = new AuthorId("author-different");
        $identities->authorClaims["author-stephen"] = [
            BibliographicProviderEntityIdentity::author(
                "open_library",
                "/authors/OL19981A"
            ),
        ];

        $result = $this->service($local, $external, $identities)->search(
            new BibliographicTextSearchRequest($query)
        );
        $items = $result->authors()->items();

        self::assertCount(3, $items);
        self::assertSame("author-stephen", $items[0]->reference()->authorId()?->value());
        self::assertSame(
            "/authors/OL19981A",
            $items[0]->reference()->providerIdentity()?->providerRecordId()
        );
        self::assertSame("/authors/OL20000A", $items[1]->reference()->providerIdentity()?->providerRecordId());
        self::assertSame("/authors/OL20001A", $items[2]->reference()->providerIdentity()?->providerRecordId());
        self::assertSame(1, $identities->mappedAuthorCalls);
        self::assertSame(1, $identities->authorClaimCalls);
        self::assertSame(9, $external->authorLimit);
    }

    public function testRemovedMappingIsReReadAndNoLongerSuppressesExternalAuthor(): void
    {
        $query = new BibliographicTextSearchQuery("stephen king");
        $local = new SearchFakeProvider([], []);
        $external = new SearchFakeProvider([
            $this->externalAuthor($query, "/authors/OL50001A", "Stephen King", 0),
        ], []);
        $identities = new SearchIdentityRepository();
        $identities->authorMappings["/authors/OL50001A"] = new AuthorId("author-old");
        $service = $this->service($local, $external, $identities);

        self::assertSame([], $service->search(
            new BibliographicTextSearchRequest($query)
        )->authors()->items());
        unset($identities->authorMappings["/authors/OL50001A"]);

        $current = $service->search(new BibliographicTextSearchRequest($query));
        self::assertCount(1, $current->authors()->items());
        self::assertSame(2, $identities->mappedAuthorCalls);
    }

    public function testMappedSuppressionAdvancesSourceAndCanReturnZeroVisibleWithContinuation(): void
    {
        $query = new BibliographicTextSearchQuery("stephen king");
        $authors = [];
        $identities = new SearchIdentityRepository();
        for ($position = 0; $position < 11; $position++) {
            $recordId = "/authors/OL" . (30000 + $position) . "A";
            $authors[] = $this->externalAuthor($query, $recordId, "Stephen King", $position);
            $identities->authorMappings[$recordId] = new AuthorId("mapped-{$position}");
        }
        $local = new PagingSearchFakeProvider([], []);
        $external = new PagingSearchFakeProvider($authors, []);
        $service = new BibliographicTextSearchService(
            new ControllableAuthenticatedUser(new UserId("search-actor")),
            $local,
            $local,
            $external,
            $external,
            $identities,
            $identities
        );

        $first = $service->search(new BibliographicTextSearchRequest($query));
        self::assertSame([], $first->authors()->items());
        self::assertSame(BibliographicAuthorSearchLane::External, $first->authors()->nextCursor()?->lane());
        self::assertSame(10, $first->authors()->nextCursor()?->nextOffset());
        self::assertSame(ProviderLookupStatus::Candidates, $first->authorProviderAttempts()[0]->status());

        $request = new BibliographicTextSearchRequest($query, $first->authors()->nextCursor());
        $second = $service->search($request);
        $replay = $service->search($request);
        self::assertSame([], $second->authors()->items());
        self::assertNull($second->authors()->nextCursor());
        self::assertSame($second->authors()->items(), $replay->authors()->items());
        self::assertSame([0, 10, 10], $external->authorOffsets);
    }

    public function testMixedPageRequestsOnlyRemainingCapacityWithoutCandidateLoss(): void
    {
        $query = new BibliographicTextSearchQuery("author");
        $localAuthors = [];
        for ($position = 0; $position < 7; $position++) {
            $localAuthors[] = $this->localAuthor(
                $query,
                "local-{$position}",
                "Author Local {$position}",
                $position
            );
        }
        $externalAuthors = [];
        for ($position = 0; $position < 5; $position++) {
            $externalAuthors[] = $this->externalAuthor(
                $query,
                "/authors/OL" . (40000 + $position) . "A",
                "Author External {$position}",
                $position
            );
        }
        $local = new PagingSearchFakeProvider($localAuthors, []);
        $external = new PagingSearchFakeProvider($externalAuthors, []);
        $identities = new SearchIdentityRepository();
        $service = new BibliographicTextSearchService(
            new ControllableAuthenticatedUser(new UserId("search-actor")),
            $local,
            $local,
            $external,
            $external,
            $identities,
            $identities
        );

        $first = $service->search(new BibliographicTextSearchRequest($query));
        self::assertCount(10, $first->authors()->items());
        self::assertSame([3], $external->authorLimits);
        self::assertSame(3, $first->authors()->nextCursor()?->nextOffset());

        $second = $service->search(new BibliographicTextSearchRequest(
            $query,
            $first->authors()->nextCursor()
        ));
        self::assertCount(2, $second->authors()->items());
        self::assertSame([0, 3], $external->authorOffsets);
        self::assertSame([3, 10], $external->authorLimits);
    }

    public function testCombinesLocalBeforeExternalWithoutMergingTextLookalikes(): void
    {
        $query = new BibliographicTextSearchQuery("Ursula Le Guin");
        $local = new SearchFakeProvider(
            [$this->localAuthor($query, "author-local", "Ursula K. Le Guin", 0)],
            [$this->localWork($query, "work-local", "The Dispossessed", 0)]
        );
        $external = new SearchFakeProvider(
            [$this->externalAuthor($query, "/authors/OL1A", "Ursula K. Le Guin", 0)],
            [$this->externalWork($query, "/works/OL1W", "The Dispossessed", 0)]
        );

        $result = $this->service($local, $external)->search(
            new BibliographicTextSearchRequest($query)
        );

        self::assertSame([
            BibliographicSearchResultKind::LocalCanonical,
            BibliographicSearchResultKind::ExternalCandidate,
        ], array_map(static fn ($item) => $item->reference()->kind(), $result->authors()->items()));
        self::assertCount(2, $result->works()->items());
        self::assertSame(ProviderLookupStatus::Candidates, $result->authorProviderAttempts()[0]->status());
        self::assertSame(ProviderLookupStatus::Candidates, $result->workProviderAttempts()[0]->status());
    }

    public function testMappedWorkDuplicateCollapsesButSameTitleWithoutMappingDoesNot(): void
    {
        $query = new BibliographicTextSearchQuery("Dune");
        $local = new SearchFakeProvider([], [
            $this->localWork($query, "work-dune", "Dune", 0),
            $this->localWork($query, "work-dune-two", "Dune", 1),
        ]);
        $external = new SearchFakeProvider([], [
            $this->externalWork($query, "/works/OL1W", "Dune", 0),
            $this->externalWork($query, "/works/OL2W", "Dune", 1),
        ]);
        $identities = new SearchIdentityRepository();
        $identities->workMappings["open_library\0work\0/works/OL1W"] = new WorkId("work-dune");

        $result = $this->service($local, $external, $identities)->search(
            new BibliographicTextSearchRequest($query)
        );

        self::assertCount(3, $result->works()->items());
        self::assertSame([
            "work-dune",
            "work-dune-two",
            "/works/OL2W",
        ], array_map(static fn ($item): string =>
            $item->reference()->workId()?->value()
                ?? $item->reference()->providerIdentity()?->providerRecordId()
                ?? "",
            $result->works()->items()
        ));
    }

    public function testProviderFailureRetainsLocalResultsAndTypedReason(): void
    {
        $query = new BibliographicTextSearchQuery("Kindred");
        $local = new SearchFakeProvider(
            [$this->localAuthor($query, "author-butler", "Octavia E. Butler", 0)],
            [$this->localWork($query, "work-kindred", "Kindred", 0)]
        );
        $external = new SearchFakeProvider([], []);
        $external->authorFailure = new BibliographicSearchProviderFailure(
            ProviderLookupStatus::ConfigurationError,
            ProviderFailureReason::Configuration
        );
        $external->workFailure = new BibliographicSearchProviderFailure(
            ProviderLookupStatus::InvalidResponse,
            ProviderFailureReason::Malformed
        );

        $result = $this->service($local, $external)->search(
            new BibliographicTextSearchRequest($query)
        );

        self::assertCount(1, $result->authors()->items());
        self::assertCount(1, $result->works()->items());
        self::assertSame(ProviderLookupStatus::ConfigurationError, $result->authorProviderAttempts()[0]->status());
        self::assertSame(ProviderFailureReason::Configuration, $result->authorProviderAttempts()[0]->failureReason());
        self::assertSame(ProviderLookupStatus::InvalidResponse, $result->workProviderAttempts()[0]->status());
        self::assertSame(ProviderFailureReason::Malformed, $result->workProviderAttempts()[0]->failureReason());
    }

    public function testValidEmptyProviderPagesAreIndependentNormalMisses(): void
    {
        $provider = new SearchFakeProvider([], []);
        $result = $this->service($provider, $provider)->search(
            new BibliographicTextSearchRequest(
                new BibliographicTextSearchQuery("no matches")
            )
        );

        self::assertSame([], $result->authors()->items());
        self::assertSame([], $result->works()->items());
        self::assertSame(ProviderLookupStatus::Miss, $result->authorProviderAttempts()[0]->status());
        self::assertSame(ProviderLookupStatus::Miss, $result->workProviderAttempts()[0]->status());
        self::assertNull($result->authorProviderAttempts()[0]->failureReason());
        self::assertNull($result->workProviderAttempts()[0]->failureReason());
    }

    public function testAuthorAndWorkCursorsAdvanceIndependently(): void
    {
        $query = new BibliographicTextSearchQuery("pageable");
        $author = $this->externalAuthor($query, "/authors/OL10A", "Author 10", 9);
        $work = $this->externalWork($query, "/works/OL20W", "Work 20", 19);
        $local = new SearchFakeProvider([], []);
        $external = new SearchFakeProvider([$author], [$work]);
        $request = new BibliographicTextSearchRequest(
            $query,
            new BibliographicAuthorSearchCursor(
                $query,
                BibliographicAuthorSearchLane::External,
                10
            ),
            $work->cursor($query)
        );

        $this->service($local, $external)->search($request);

        self::assertSame(10, $external->authorOffset);
        self::assertSame(19, $external->workCursor?->presentationOrder());
        self::assertSame(0, $local->authorCalls);
        self::assertNull($local->workCursor);
    }

    public function testExternalAuthorSearchWaitsUntilLocalTraversalFinishes(): void
    {
        $query = new BibliographicTextSearchQuery("broad query");
        $authors = [];
        $works = [];
        for ($position = 0; $position < 10; $position++) {
            $authors[] = $this->localAuthor($query, "author-{$position}", "Author {$position}", $position);
            $works[] = $this->localWork($query, "work-{$position}", "Work {$position}", $position);
        }
        $local = new SearchFakeProvider($authors, $works, true, true);
        $external = new SearchFakeProvider(
            [$this->externalAuthor($query, "/authors/OL1A", "External", 0)],
            [$this->externalWork($query, "/works/OL1W", "External", 0)]
        );

        $result = $this->service($local, $external)->search(
            new BibliographicTextSearchRequest($query)
        );

        self::assertCount(10, $result->authors()->items());
        self::assertCount(10, $result->works()->items());
        self::assertNotNull($result->authors()->nextCursor());
        self::assertNotNull($result->works()->nextCursor());
        self::assertSame(0, $external->authorCalls);
        self::assertSame(1, $external->workCalls);
    }

    public function testAuthenticationPrecedesEverySearchCall(): void
    {
        $provider = new SearchFakeProvider([], []);
        $service = new BibliographicTextSearchService(
            new ControllableAuthenticatedUser(),
            $provider,
            $provider,
            $provider,
            $provider,
            new SearchIdentityRepository(),
            new SearchIdentityRepository()
        );

        $this->expectException(AuthenticationException::class);
        try {
            $service->search(new BibliographicTextSearchRequest(
                new BibliographicTextSearchQuery("unauthenticated")
            ));
        } finally {
            self::assertSame(0, $provider->authorCalls);
            self::assertSame(0, $provider->workCalls);
        }
    }

    private function service(
        SearchFakeProvider $local,
        SearchFakeProvider $external,
        ?SearchIdentityRepository $identities = null
    ): BibliographicTextSearchService {
        return new BibliographicTextSearchService(
            new ControllableAuthenticatedUser(new UserId("search-actor")),
            $local,
            $local,
            $external,
            $external,
            $identities ?? new SearchIdentityRepository(),
            $identities ?? new SearchIdentityRepository()
        );
    }

    private function localAuthor(
        BibliographicTextSearchQuery $query,
        string $id,
        string $name,
        int $order
    ): BibliographicAuthorSearchResult {
        return new BibliographicAuthorSearchResult(
            BibliographicAuthorReference::canonical(new AuthorId($id)),
            $name,
            $order,
            $query
        );
    }

    private function externalAuthor(
        BibliographicTextSearchQuery $query,
        string $id,
        string $name,
        int $order
    ): BibliographicAuthorSearchResult {
        return new BibliographicAuthorSearchResult(
            BibliographicAuthorReference::external(
                BibliographicProviderEntityIdentity::author("open_library", $id)
            ),
            $name,
            $order,
            $query
        );
    }

    private function localWork(
        BibliographicTextSearchQuery $query,
        string $id,
        string $title,
        int $order
    ): BibliographicWorkSearchResult {
        return new BibliographicWorkSearchResult(
            BibliographicWorkReference::canonical(new WorkId($id)),
            $title,
            [new BibliographicWorkAuthor("Local Author")],
            [],
            $order
        );
    }

    private function externalWork(
        BibliographicTextSearchQuery $query,
        string $id,
        string $title,
        int $order
    ): BibliographicWorkSearchResult {
        return new BibliographicWorkSearchResult(
            BibliographicWorkReference::external(
                BibliographicProviderEntityIdentity::work("open_library", $id)
            ),
            $title,
            [new BibliographicWorkAuthor("External Author")],
            [],
            $order
        );
    }
}

final class SearchFakeProvider implements
    BibliographicAuthorSearchProvider,
    BibliographicWorkSearchProvider
{
    public int $authorCalls = 0;
    public int $workCalls = 0;
    public ?int $authorOffset = null;
    public ?int $authorLimit = null;
    public ?BibliographicSearchCursor $workCursor = null;
    public ?BibliographicSearchProviderFailure $authorFailure = null;
    public ?BibliographicSearchProviderFailure $workFailure = null;

    /**
     * @param list<BibliographicAuthorSearchResult> $authors
     * @param list<BibliographicWorkSearchResult> $works
     */
    public function __construct(
        private array $authors,
        private array $works,
        private bool $moreAuthors = false,
        private bool $moreWorks = false
    ) {
    }

    public function key(): string { return "open_library"; }

    public function searchAuthors(
        BibliographicTextSearchQuery $query,
        int $offset = 0,
        int $limit = BibliographicTextSearchService::PAGE_SIZE
    ): BibliographicAuthorSearchSourcePage {
        $this->authorCalls++;
        $this->authorOffset = $offset;
        $this->authorLimit = $limit;
        if ($this->authorFailure !== null) { throw $this->authorFailure; }
        return new BibliographicAuthorSearchSourcePage(
            $this->authors,
            $this->moreAuthors ? $offset + count($this->authors) : null
        );
    }

    public function searchWorks(
        BibliographicTextSearchQuery $query,
        ?BibliographicSearchCursor $cursor = null
    ): BibliographicWorkSearchPage {
        $this->workCalls++;
        $this->workCursor = $cursor;
        if ($this->workFailure !== null) { throw $this->workFailure; }
        $last = $this->works === [] ? null : $this->works[array_key_last($this->works)];
        return new BibliographicWorkSearchPage(
            $query,
            $this->works,
            $this->moreWorks && $last !== null ? $last->cursor($query) : null
        );
    }
}

final class SearchIdentityRepository implements
    BibliographicProviderIdentityRepository,
    BibliographicAuthorProviderIdentityLookup
{
    /** @var array<string,WorkId> */
    public array $workMappings = [];
    /** @var array<string,AuthorId> */
    public array $authorMappings = [];
    /** @var array<string,list<BibliographicProviderEntityIdentity>> */
    public array $authorClaims = [];
    public int $mappedAuthorCalls = 0;
    public int $authorClaimCalls = 0;

    public function findWork(string $provider, string $sourceType, string $recordId): ?WorkId
    {
        return $this->workMappings["{$provider}\0{$sourceType}\0{$recordId}"] ?? null;
    }

    public function findEdition(string $provider, string $recordId): ?EditionId { return null; }
    public function mappedAuthors(string $providerKey, array $providerAuthorRecordIds): array
    {
        $this->mappedAuthorCalls++;
        $result = [];
        foreach ($providerAuthorRecordIds as $recordId) {
            if (isset($this->authorMappings[$recordId])) {
                $result[$recordId] = $this->authorMappings[$recordId];
            }
        }
        return $result;
    }
    public function providerAuthorIdentities(string $providerKey, array $authorIds): array
    {
        $this->authorClaimCalls++;
        $result = [];
        foreach ($authorIds as $authorId) {
            if (isset($this->authorClaims[$authorId->value()])) {
                $result[$authorId->value()] = $this->authorClaims[$authorId->value()];
            }
        }
        return $result;
    }
    public function claimWork(string $provider, string $sourceType, string $recordId, WorkId $workId): void {}
    public function claimEdition(string $provider, string $recordId, EditionId $editionId): void {}
}

final class PagingSearchFakeProvider implements
    BibliographicAuthorSearchProvider,
    BibliographicWorkSearchProvider
{
    /** @var list<int> */ public array $authorOffsets = [];
    /** @var list<int> */ public array $authorLimits = [];

    /**
     * @param list<BibliographicAuthorSearchResult> $authors
     * @param list<BibliographicWorkSearchResult> $works
     */
    public function __construct(private array $authors, private array $works) {}

    public function key(): string { return "open_library"; }

    public function searchAuthors(
        BibliographicTextSearchQuery $query,
        int $offset = 0,
        int $limit = BibliographicTextSearchService::PAGE_SIZE
    ): BibliographicAuthorSearchSourcePage {
        $this->authorOffsets[] = $offset;
        $this->authorLimits[] = $limit;
        $items = array_slice($this->authors, $offset, $limit);
        $next = $offset + count($items) < count($this->authors)
            ? $offset + count($items)
            : null;
        return new BibliographicAuthorSearchSourcePage($items, $next);
    }

    public function searchWorks(
        BibliographicTextSearchQuery $query,
        ?BibliographicSearchCursor $cursor = null
    ): BibliographicWorkSearchPage {
        return new BibliographicWorkSearchPage($query, $this->works, null);
    }
}
