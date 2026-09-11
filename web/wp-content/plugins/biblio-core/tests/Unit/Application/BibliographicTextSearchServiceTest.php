<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderIdentityRepository;
use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorReference;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchPage;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchProvider;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchResult;
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
            $author->cursor($query),
            $work->cursor($query)
        );

        $this->service($local, $external)->search($request);

        self::assertSame(9, $external->authorCursor?->presentationOrder());
        self::assertSame(19, $external->workCursor?->presentationOrder());
        self::assertNull($local->authorCursor);
        self::assertNull($local->workCursor);
    }

    public function testExternalSearchStillRunsWhenLocalPageContinues(): void
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
        self::assertSame(1, $external->authorCalls);
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
            $order
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
            $order
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
    public ?BibliographicSearchCursor $authorCursor = null;
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
        ?BibliographicSearchCursor $cursor = null
    ): BibliographicAuthorSearchPage {
        $this->authorCalls++;
        $this->authorCursor = $cursor;
        if ($this->authorFailure !== null) { throw $this->authorFailure; }
        $last = $this->authors === [] ? null : $this->authors[array_key_last($this->authors)];
        return new BibliographicAuthorSearchPage(
            $query,
            $this->authors,
            $this->moreAuthors && $last !== null ? $last->cursor($query) : null
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

final class SearchIdentityRepository implements BibliographicProviderIdentityRepository
{
    /** @var array<string,WorkId> */
    public array $workMappings = [];

    public function findWork(string $provider, string $sourceType, string $recordId): ?WorkId
    {
        return $this->workMappings["{$provider}\0{$sourceType}\0{$recordId}"] ?? null;
    }

    public function findEdition(string $provider, string $recordId): ?EditionId { return null; }
    public function claimWork(string $provider, string $sourceType, string $recordId, WorkId $workId): void {}
    public function claimEdition(string $provider, string $recordId, EditionId $editionId): void {}
}
