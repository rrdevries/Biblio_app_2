<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Catalog\Query\{CatalogArchiveScope,CatalogCollectionFilter,CatalogFilters,CatalogQuery,CatalogQuerySort,CatalogSearchTerm};
use Biblio\Core\Application\Catalog\Read\CatalogOverviewPageSize;
use Biblio\Core\Application\Catalog\Query\CatalogQueryCursorCodec;
use Biblio\Core\Application\Catalog\Query\CatalogQueryService;
use Biblio\Core\Application\Catalog\Classification\Read\LibraryClassificationQueryService;
use Biblio\Core\Application\Catalog\Read\{BibliographicRelationshipQueryService,LibraryItemLocationQueryService};
use Biblio\Core\Application\Collections\Read\LibraryCollectionQueryService;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Application\Reading\GetPersonalWorkReadingStatusService;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\Classification\{LibraryBookTypeId,LibraryGenreId,LibrarySubjectId};
use Biblio\Core\Catalog\{AuthorId,ItemId,LocationId,SeriesId};
use Biblio\Core\Collections\CollectionId;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{WpdbActorLibraryContextRepository,WpdbAuthorRepository,WpdbCatalogQueryRepository,WpdbCollectionRepository,WpdbLibraryClassificationReadRepository,WpdbLocationRepository,WpdbReadingRoundRepository,WpdbSeriesRepository};
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Reading\PersonalWorkReadingStatus;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;

final class CatalogQueryCompositionTest extends PersistenceIntegrationTestCase
{
    private WpdbCatalogQueryRepository $repository;
    private LibraryId $library;
    private UserId $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new WpdbCatalogQueryRepository($this->database, $this->tableNames);
        $this->library = new LibraryId('library-a');
        $this->actor = new UserId('501');
        $this->seedLibrary('library-a');
        $this->seedLibrary('library-b');
    }

    public function testItemIdentityTitleSortAndKeysetAreStableWithoutDuplicateRows(): void
    {
        $this->seedItem('item-a2', 'library-a', 'work-a', 'Alpha');
        $this->seedItem('item-a1', 'library-a', 'work-a', 'Alpha');
        $this->seedItem('item-b', 'library-a', 'work-b', 'Beta');
        $this->seedItem('item-foreign', 'library-b', 'work-foreign', 'Foreign');
        $this->seedAuthor('author-a', 'Auteur A', 'work-a', 1);
        $this->seedAuthor('author-b', 'Auteur B', 'work-a', 2);
        $this->seedSeries('series-a', 'Serie A', 'work-a', '1.000000');
        $this->seedSeries('series-b', 'Serie B', 'work-a', '2.000000');

        $first = $this->repository->page(
            $this->library,
            $this->actor,
            new CatalogQuery(pageSize: new CatalogOverviewPageSize(2)),
            null
        );
        self::assertSame(['item-a1', 'item-a2'], $this->ids($first));
        self::assertTrue($first->hasMore());

        $second = $this->repository->page(
            $this->library,
            $this->actor,
            new CatalogQuery(pageSize: new CatalogOverviewPageSize(2)),
            new ItemId('item-a2')
        );
        self::assertSame(['item-b'], $this->ids($second));
        self::assertFalse($second->hasMore());
    }

    public function testSearchCoversCanonicalSourcesAndKeepsOmnibusItemIdentity(): void
    {
        $this->seedItem('item-main', 'library-a', 'work-main', 'Main title', 'INV-42', '9781234567890');
        $this->seedItem('item-omnibus', 'library-a', 'work-omnibus', 'Collected volume');
        $this->seedItem('item-other', 'library-a', 'work-other', 'Other');
        $this->seedAuthor('author-main', 'Renée Writer', 'work-main', 1);
        $this->seedSeries('series-main', 'Saga Name', 'work-main', '1.000000');
        $this->database->insert($this->tableNames->workAlternateTitles(), [
            'work_id' => 'work-main', 'alternate_title' => 'Hidden Alpha', 'normalized_title' => 'hidden alpha',
        ]);
        $this->database->insert($this->tableNames->works(), ['work_id' => 'work-contained', 'work_title' => 'Contained Jewel']);
        $this->database->insert($this->tableNames->workContainments(), [
            'parent_work_id' => 'work-omnibus', 'contained_work_id' => 'work-contained', 'contained_position' => 1,
        ]);
        $this->database->insert($this->tableNames->workAlternateTitles(), [
            'work_id' => 'work-contained', 'alternate_title' => 'Nested Secret', 'normalized_title' => 'nested secret',
        ]);
        $this->seedAuthor('author-contained', 'Nested Writer', 'work-contained', 1);
        $this->seedSeries('series-contained', 'Nested Series', 'work-contained', '3.000000');

        foreach ([
            'Main' => 'item-main',
            'Hidden' => 'item-main',
            'Renée' => 'item-main',
            'Renee' => 'item-main',
            'Saga' => 'item-main',
            '9781234567890' => 'item-main',
            'INV-42' => 'item-main',
            'Jewel' => 'item-omnibus',
            'Secret' => 'item-omnibus',
            'Nested Writer' => 'item-omnibus',
            'Nested Series' => 'item-omnibus',
        ] as $term => $expected) {
            $page = $this->repository->page(
                $this->library,
                $this->actor,
                new CatalogQuery(search: new CatalogSearchTerm((string) $term)),
                null
            );
            self::assertSame(
                [$expected],
                $this->ids($page),
                "Search failed for {$term}: {$this->database->last_error}; {$this->database->last_query}"
            );
        }
        $omnibus = $this->repository->page(
            $this->library,
            $this->actor,
            new CatalogQuery(search: new CatalogSearchTerm('Jewel')),
            null
        )->records()[0];
        self::assertSame('Contained Jewel', $omnibus->containedMatchTitle());
    }

    public function testSearchRanksExactTitleBeforePartialTitleAndPaginatesWithoutDuplicates(): void
    {
        $this->seedItem('item-exact', 'library-a', 'work-exact', 'Shared title');
        $this->seedItem('item-partial', 'library-a', 'work-partial', 'Shared title extended');
        $this->seedItem('item-exact-copy', 'library-a', 'work-exact-copy', 'Shared title');
        $query = new CatalogQuery(search: new CatalogSearchTerm('Shared title'), pageSize: new CatalogOverviewPageSize(2));

        $first = $this->repository->page($this->library, $this->actor, $query, null);
        self::assertSame(['item-exact', 'item-exact-copy'], $this->ids($first));
        self::assertTrue($first->hasMore());
        $second = $this->repository->page($this->library, $this->actor, $query, new ItemId('item-exact-copy'));
        self::assertSame(['item-partial'], $this->ids($second));
        self::assertFalse($second->hasMore());
        self::assertSame([], array_intersect($this->ids($first), $this->ids($second)));
    }

    public function testEveryCanonicalFilterUsesOrWithinGroupAndAndAcrossGroups(): void
    {
        $this->seedItem('item-match', 'library-a', 'work-match', 'Match', null, null, 'location-a');
        $this->seedItem('item-other', 'library-a', 'work-other', 'Other', null, null, 'location-b');
        $this->seedAuthor('author-a', 'Author', 'work-match', 1);
        $this->seedAuthor('author-extra', 'Co-author', 'work-match', 2);
        $this->seedSeries('series-a', 'Series', 'work-match', '2.000000');
        $this->seedSeries('series-extra', 'Other Series', 'work-match', '1.000000');
        $this->seedClassifications('work-match');
        $this->database->insert($this->tableNames->libraryGenres(), [
            'library_id' => 'library-a', 'genre_id' => 'genre-extra', 'display_name' => 'Extra genre',
            'normalized_name' => 'extra genre', 'term_status' => 'active',
        ]);
        $this->database->insert($this->tableNames->libraryCatalogContextGenres(), [
            'library_id' => 'library-a', 'work_id' => 'work-match', 'genre_id' => 'genre-extra',
        ]);
        $this->database->insert($this->tableNames->librarySubjects(), [
            'library_id' => 'library-a', 'subject_id' => 'subject-extra', 'display_name' => 'Extra subject',
            'normalized_name' => 'extra subject', 'term_status' => 'active',
        ]);
        $this->database->insert($this->tableNames->libraryCatalogContextSubjects(), [
            'library_id' => 'library-a', 'work_id' => 'work-match', 'subject_id' => 'subject-extra',
        ]);
        $this->seedCollection('collection-a', 'item-match', true);
        $this->seedCollection('collection-extra', 'item-match', true);
        $this->seedRound('round-read', '501', 'work-match', 'completed');
        self::assertSame(
            'completed',
            $this->database->get_var("SELECT round_outcome FROM `{$this->tableNames->readingRounds()}` WHERE reading_round_id='round-read'")
        );
        $roundRow = $this->database->get_row("SELECT user_id,work_id,round_outcome FROM `{$this->tableNames->readingRounds()}` WHERE reading_round_id='round-read'", ARRAY_A);
        self::assertSame(['user_id' => '501', 'work_id' => 'work-match', 'round_outcome' => 'completed'], $roundRow);
        self::assertSame(
            '0',
            (string) $this->database->get_var("SELECT EXISTS(SELECT 1 FROM `{$this->tableNames->readingRounds()}` WHERE user_id='501' AND work_id='work-match' AND round_outcome IS NULL)")
        );

        $filters = new CatalogFilters(
            readingStatuses: [PersonalWorkReadingStatus::Read, PersonalWorkReadingStatus::Reading],
            authorIds: [new AuthorId('author-a'), new AuthorId('unknown-author')],
            seriesIds: [new SeriesId('series-a'), new SeriesId('unknown-series')],
            locationIds: [new LocationId('location-a')],
            bookTypeIds: [new LibraryBookTypeId('book-a')],
            genreIds: [new LibraryGenreId('genre-a'), new LibraryGenreId('genre-unknown')],
            subjectIds: [new LibrarySubjectId('subject-a')],
            collections: CatalogCollectionFilter::in([new CollectionId('collection-a')])
        );
        foreach ([
            new CatalogFilters(readingStatuses: [PersonalWorkReadingStatus::Read]),
            new CatalogFilters(authorIds: [new AuthorId('author-a')]),
            new CatalogFilters(seriesIds: [new SeriesId('series-a')]),
            new CatalogFilters(locationIds: [new LocationId('location-a')]),
            new CatalogFilters(bookTypeIds: [new LibraryBookTypeId('book-a')]),
            new CatalogFilters(genreIds: [new LibraryGenreId('genre-a')]),
            new CatalogFilters(subjectIds: [new LibrarySubjectId('subject-a')]),
            new CatalogFilters(collections: CatalogCollectionFilter::in([new CollectionId('collection-a')])),
        ] as $singleFilter) {
            self::assertSame(
                ['item-match'],
                $this->ids($this->repository->page($this->library, $this->actor, new CatalogQuery(filters: $singleFilter), null)),
                "Single filter failed: {$this->database->last_query}"
            );
        }
        $page = $this->repository->page($this->library, $this->actor, new CatalogQuery(filters: $filters), null);
        self::assertSame(
            ['item-match'],
            $this->ids($page),
            "Combined filter failed: {$this->database->last_error}; {$this->database->last_query}"
        );

        $none = $this->repository->page(
            $this->library,
            $this->actor,
            new CatalogQuery(filters: new CatalogFilters(authorIds: [new AuthorId('unknown-author')])),
            null
        );
        self::assertSame([], $this->ids($none));
    }

    public function testInactiveAndForeignClassificationsCannotMatchTheCurrentLibrary(): void
    {
        $this->seedItem('item-classified', 'library-a', 'work-classified', 'Classified');
        $this->seedClassifications('work-classified');
        self::assertSame(1, $this->database->update(
            $this->tableNames->libraryGenres(),
            ['term_status' => 'inactive'],
            ['library_id' => 'library-a', 'genre_id' => 'genre-a']
        ));
        $this->database->insert($this->tableNames->libraryBookTypes(), [
            'library_id' => 'library-b', 'book_type_id' => 'book-foreign', 'display_name' => 'Foreign book type',
            'normalized_name' => 'foreign book type', 'term_status' => 'active',
        ]);
        $this->database->insert($this->tableNames->libraryGenres(), [
            'library_id' => 'library-b', 'genre_id' => 'genre-foreign', 'display_name' => 'Foreign',
            'normalized_name' => 'foreign', 'term_status' => 'active',
        ]);
        $this->database->insert($this->tableNames->libraryCatalogContexts(), [
            'library_id' => 'library-b', 'work_id' => 'work-classified',
            'book_type_id' => 'book-foreign', 'context_version' => 1,
        ]);
        $this->database->insert($this->tableNames->libraryCatalogContextGenres(), [
            'library_id' => 'library-b', 'work_id' => 'work-classified', 'genre_id' => 'genre-foreign',
        ]);

        foreach (['genre-a', 'genre-foreign'] as $genreId) {
            $page = $this->repository->page(
                $this->library,
                $this->actor,
                new CatalogQuery(filters: new CatalogFilters(genreIds: [new LibraryGenreId($genreId)])),
                null
            );
            self::assertSame([], $this->ids($page));
        }
    }

    public function testArchiveCollectionLifecycleAndSortContractsRemainExplicit(): void
    {
        $this->seedItem('item-active', 'library-a', 'work-active', 'Zulu');
        $this->seedItem('item-archived', 'library-a', 'work-archived', 'Alpha', null, null, null, 'archived');
        $this->seedItem('item-none', 'library-a', 'work-none', 'No Collection');
        $this->seedItem('item-historical', 'library-a', 'work-historical', 'Historical Membership');
        $this->seedCollection('collection-active', 'item-active', true);
        $this->seedCollection('collection-archived', 'item-none', false);
        $this->seedCollection('collection-historical', 'item-historical', true, false);

        $active = $this->repository->page($this->library, $this->actor, new CatalogQuery(), null);
        self::assertSame(['item-historical', 'item-none', 'item-active'], $this->ids($active));
        $mixed = $this->repository->page(
            $this->library,
            $this->actor,
            new CatalogQuery(archiveScope: CatalogArchiveScope::ActiveAndArchived),
            null
        );
        self::assertSame(['item-archived', 'item-historical', 'item-none', 'item-active'], $this->ids($mixed));
        $without = $this->repository->page(
            $this->library,
            $this->actor,
            new CatalogQuery(filters: new CatalogFilters(collections: CatalogCollectionFilter::withoutCollection())),
            null
        );
        self::assertSame(['item-historical', 'item-none'], $this->ids($without));
        $historicalCollection = $this->repository->page(
            $this->library,
            $this->actor,
            new CatalogQuery(filters: new CatalogFilters(collections: CatalogCollectionFilter::in([new CollectionId('collection-historical')]))),
            null
        );
        self::assertSame([], $this->ids($historicalCollection));
    }

    public function testReadingStatusFilterUsesOnlyTheCurrentActor(): void
    {
        $this->seedItem('item-private-status', 'library-a', 'work-private-status', 'Private status');
        $this->seedRound('round-other-user', '777', 'work-private-status', 'completed');

        $notRead = $this->repository->page(
            $this->library,
            $this->actor,
            new CatalogQuery(filters: new CatalogFilters(readingStatuses: [PersonalWorkReadingStatus::NotRead])),
            null
        );
        self::assertSame(['item-private-status'], $this->ids($notRead));
        $read = $this->repository->page(
            $this->library,
            $this->actor,
            new CatalogQuery(filters: new CatalogFilters(readingStatuses: [PersonalWorkReadingStatus::Read])),
            null
        );
        self::assertSame([], $this->ids($read));
    }

    public function testAuthorAndSeriesSortsUseStableNullBucketsAndKeysetTieBreakers(): void
    {
        $this->seedItem('item-author-b', 'library-a', 'work-author-b', 'Same');
        $this->seedItem('item-author-a2', 'library-a', 'work-author-a2', 'Same');
        $this->seedItem('item-author-a1', 'library-a', 'work-author-a1', 'Same');
        $this->seedItem('item-author-none', 'library-a', 'work-author-none', 'Same');
        $this->seedAuthor('author-b', 'Bravo', 'work-author-b', 1);
        $this->seedAuthor('author-a1', 'Alpha', 'work-author-a1', 1);
        $this->seedAuthor('author-a2', 'Alpha', 'work-author-a2', 1);

        $authorQuery = new CatalogQuery(sort: CatalogQuerySort::Author, pageSize: new CatalogOverviewPageSize(2));
        $authorFirst = $this->repository->page($this->library, $this->actor, $authorQuery, null);
        self::assertSame(['item-author-a1', 'item-author-a2'], $this->ids($authorFirst));
        $authorSecond = $this->repository->page($this->library, $this->actor, $authorQuery, new ItemId('item-author-a2'));
        self::assertSame(['item-author-b', 'item-author-none'], $this->ids($authorSecond));

        $this->database->insert($this->tableNames->series(), ['series_id' => 'series-sort', 'display_name' => 'Series']);
        $this->attachSeries('series-sort', 'work-author-a1', '2.000000');
        $this->attachSeries('series-sort', 'work-author-a2', '1.000000');
        $this->attachSeries('series-sort', 'work-author-b', null);
        $seriesQuery = new CatalogQuery(
            filters: new CatalogFilters(seriesIds: [new SeriesId('series-sort')]),
            sort: CatalogQuerySort::Series,
            pageSize: new CatalogOverviewPageSize(2)
        );
        $seriesFirst = $this->repository->page($this->library, $this->actor, $seriesQuery, null);
        self::assertSame(['item-author-a2', 'item-author-a1'], $this->ids($seriesFirst));
        $seriesSecond = $this->repository->page($this->library, $this->actor, $seriesQuery, new ItemId('item-author-a1'));
        self::assertSame(['item-author-b'], $this->ids($seriesSecond));
    }

    public function testApplicationBoundaryAuthorizesAndBatchEnrichesOnlySelectedPage(): void
    {
        $this->database->insert($this->tableNames->memberships(), [
            'library_id' => 'library-a', 'user_id' => '501', 'membership_status' => 'active',
            'management_role' => 'owner', 'use_access' => 'direct', 'additional_permissions' => '[]',
        ]);
        $this->seedItem('item-service-a', 'library-a', 'work-service-a', 'Alpha', 'A-1', null, 'location-a');
        $this->seedItem('item-service-b', 'library-a', 'work-service-b', 'Beta', 'B-1', null, 'location-b');
        $this->seedAuthor('author-service', 'Author', 'work-service-a', 1);
        $this->seedAuthor('author-service-b', 'Author B', 'work-service-b', 1);
        $this->seedSeries('series-service', 'Series', 'work-service-a', '1.000000');
        $this->seedSeries('series-service-b', 'Series B', 'work-service-b', '2.000000');
        $this->seedClassifications('work-service-a');
        $this->database->insert($this->tableNames->libraryCatalogContexts(), [
            'library_id' => 'library-a', 'work_id' => 'work-service-b', 'book_type_id' => 'book-a', 'context_version' => 1,
        ]);
        $this->database->insert($this->tableNames->libraryCatalogContextGenres(), [
            'library_id' => 'library-a', 'work_id' => 'work-service-b', 'genre_id' => 'genre-a',
        ]);
        $this->database->insert($this->tableNames->libraryCatalogContextSubjects(), [
            'library_id' => 'library-a', 'work_id' => 'work-service-b', 'subject_id' => 'subject-a',
        ]);
        $this->seedCollection('collection-service', 'item-service-a', true);
        $this->seedCollection('collection-service-b', 'item-service-b', true);
        $this->seedRound('round-service', '501', 'work-service-a', 'completed');
        $this->seedRound('round-service-b', '501', 'work-service-b', 'completed');
        $authenticated = new ControllableAuthenticatedUser($this->actor);
        $contexts = new LibraryContextQueryService(
            $authenticated,
            new WpdbActorLibraryContextRepository($this->database, $this->tableNames),
            new LibraryAuthorizationPolicy()
        );
        $authors = new WpdbAuthorRepository($this->database, $this->tableNames);
        $series = new WpdbSeriesRepository($this->database, $this->tableNames);
        $service = new CatalogQueryService(
            $authenticated,
            $contexts,
            $this->repository,
            new CatalogQueryCursorCodec(str_repeat('s', 32)),
            new BibliographicRelationshipQueryService($authors, $series),
            new LibraryClassificationQueryService($contexts, new WpdbLibraryClassificationReadRepository($this->database, $this->tableNames)),
            new LibraryItemLocationQueryService($contexts, new WpdbLocationRepository($this->database, $this->tableNames)),
            new LibraryCollectionQueryService($contexts, new WpdbCollectionRepository($this->database, $this->tableNames)),
            new GetPersonalWorkReadingStatusService($authenticated, new WpdbReadingRoundRepository($this->database, $this->tableNames))
        );
        $before = $this->database->num_queries;
        $page = $service->query($this->library, new CatalogQuery(pageSize: new CatalogOverviewPageSize(1)));
        self::assertSame(15, $this->database->num_queries - $before);
        self::assertSame(['item-service-a'], array_map(static fn ($item): string => $item->itemId()->value(), $page->items()));
        self::assertSame('Author', $page->items()[0]->authors()[0]->displayName());
        self::assertSame('Series', $page->items()[0]->series()[0]->series()->displayName());
        self::assertSame('location-a', $page->items()[0]->location()?->id()->value());
        self::assertSame('book-a', $page->items()[0]->classification()?->bookTypeId()->value());
        self::assertSame(['collection-service'], array_map(static fn (CollectionId $id): string => $id->value(), $page->items()[0]->collectionIds()));
        self::assertSame(PersonalWorkReadingStatus::Read, $page->items()[0]->readingStatus());
        self::assertNotNull($page->nextCursor());

        $nextQuery = new CatalogQuery(pageSize: new CatalogOverviewPageSize(1), cursor: $page->nextCursor());
        $before = $this->database->num_queries;
        $nextPage = $service->query($this->library, $nextQuery);
        self::assertSame(16, $this->database->num_queries - $before);
        self::assertSame(['item-service-b'], array_map(static fn ($item): string => $item->itemId()->value(), $nextPage->items()));

        self::assertSame(1, $this->database->update(
            $this->tableNames->items(),
            ['item_status' => 'archived'],
            ['library_id' => 'library-a', 'item_id' => 'item-service-a']
        ));
        try {
            $service->query($this->library, $nextQuery);
            self::fail('A cursor whose anchor left the effective query was accepted.');
        } catch (\Biblio\Core\Exception\ValidationException) {
            self::assertTrue(true);
        }

        try {
            $service->query($this->library, new CatalogQuery(sort: CatalogQuerySort::Series));
            self::fail('Series sort without an active Series filter was accepted.');
        } catch (\Biblio\Core\Exception\ValidationException) {
            self::assertTrue(true);
        }

        $empty = $service->query(
            $this->library,
            new CatalogQuery(filters: new CatalogFilters(authorIds: [new AuthorId('unknown-author')]))
        );
        self::assertSame([], $empty->items());
        self::assertNull($empty->nextCursor());

        $this->expectException(AuthorizationException::class);
        $service->query(new LibraryId('library-b'), new CatalogQuery());
    }

    /** @return list<string> */
    private function ids(\Biblio\Core\Application\Catalog\Query\CatalogQueryRecordPage $page): array
    {
        return array_map(static fn ($record): string => $record->itemId()->value(), $page->records());
    }

    private function seedLibrary(string $id): void
    {
        $this->database->insert($this->tableNames->libraries(), [
            'library_id' => $id, 'library_name' => $id, 'library_type' => 'private_library', 'library_status' => 'active',
        ]);
    }

    private function seedItem(string $item, string $library, string $work, string $title, ?string $inventory = null, ?string $isbn13 = null, ?string $location = null, string $status = 'active'): void
    {
        if ($location !== null && (int) $this->database->get_var($this->database->prepare("SELECT COUNT(*) FROM `{$this->tableNames->locations()}` WHERE library_id=%s AND location_id=%s", $library, $location)) === 0) {
            $this->database->insert($this->tableNames->locations(), ['library_id' => $library, 'location_id' => $location, 'display_name' => $location]);
        }
        if ((int) $this->database->get_var($this->database->prepare("SELECT COUNT(*) FROM `{$this->tableNames->works()}` WHERE work_id=%s", $work)) === 0) {
            $this->database->insert($this->tableNames->works(), ['work_id' => $work, 'work_title' => $title]);
        }
        $edition = 'edition-' . $item;
        $this->database->insert($this->tableNames->editions(), ['edition_id' => $edition, 'work_id' => $work, 'isbn_13' => $isbn13]);
        $this->database->insert($this->tableNames->items(), [
            'item_id' => $item, 'library_id' => $library, 'edition_id' => $edition, 'item_status' => $status,
            'inventory_number' => $inventory, 'location_id' => $location,
        ]);
    }

    private function seedAuthor(string $id, string $name, string $work, int $position): void
    {
        $this->database->insert($this->tableNames->authors(), ['author_id' => $id, 'display_name' => $name]);
        $this->database->insert($this->tableNames->workContributors(), [
            'work_id' => $work, 'author_id' => $id, 'contributor_role' => $position === 1 ? 'author' : 'co_author', 'contributor_position' => $position,
        ]);
    }

    private function seedSeries(string $id, string $name, string $work, string $position): void
    {
        $this->database->insert($this->tableNames->series(), ['series_id' => $id, 'display_name' => $name]);
        $this->database->insert($this->tableNames->workSeries(), ['work_id' => $work, 'series_id' => $id, 'series_position' => $position]);
    }

    private function attachSeries(string $id, string $work, ?string $position): void
    {
        $this->database->insert($this->tableNames->workSeries(), ['work_id' => $work, 'series_id' => $id, 'series_position' => $position]);
    }

    private function seedClassifications(string $work): void
    {
        foreach ([['libraryBookTypes', 'book_type_id', 'book-a'], ['libraryGenres', 'genre_id', 'genre-a'], ['librarySubjects', 'subject_id', 'subject-a']] as [$method, $column, $id]) {
            $this->database->insert($this->tableNames->{$method}(), ['library_id' => 'library-a', $column => $id, 'display_name' => $id, 'normalized_name' => $id, 'term_status' => 'active']);
        }
        $this->database->insert($this->tableNames->libraryCatalogContexts(), ['library_id' => 'library-a', 'work_id' => $work, 'book_type_id' => 'book-a', 'context_version' => 1]);
        $this->database->insert($this->tableNames->libraryCatalogContextGenres(), ['library_id' => 'library-a', 'work_id' => $work, 'genre_id' => 'genre-a']);
        $this->database->insert($this->tableNames->libraryCatalogContextSubjects(), ['library_id' => 'library-a', 'work_id' => $work, 'subject_id' => 'subject-a']);
    }

    private function seedCollection(string $id, string $item, bool $active, bool $activeMembership = true): void
    {
        $status = $active ? 'active' : 'archived';
        $this->database->insert($this->tableNames->collections(), [
            'library_id' => 'library-a', 'collection_id' => $id, 'collection_name' => $id, 'normalized_name' => $id,
            'collection_status' => $status, 'collection_position' => 1, 'collection_version' => 1,
            'created_at' => '2026-09-04 10:00:00.000000', 'updated_at' => '2026-09-04 10:00:00.000000',
        ]);
        $this->database->insert($this->tableNames->collectionMemberships(), [
            'library_id' => 'library-a', 'membership_id' => 'membership-' . $id, 'collection_id' => $id, 'item_id' => $item,
            'membership_status' => $activeMembership ? 'active' : 'inactive', 'item_position' => 1,
            'added_at' => '2026-09-04 10:00:00.000000',
            'ended_at' => $activeMembership ? null : '2026-09-04 11:00:00.000000',
            'end_reason' => $activeMembership ? null : 'removed',
        ]);
    }

    private function seedRound(string $id, string $actor, string $work, ?string $outcome): void
    {
        $inserted = $this->database->insert($this->tableNames->readingRounds(), [
            'reading_round_id' => $id, 'user_id' => $actor, 'work_id' => $work, 'round_outcome' => $outcome,
            'provenance' => 'historical_manual', 'reading_finished_year' => $outcome === null ? null : 2026,
            'created_at' => '2026-09-04 10:00:00.000000', 'updated_at' => '2026-09-04 10:00:00.000000',
            'ended_at' => $outcome === null ? null : '2026-09-04 10:00:00.000000', 'round_version' => 1,
        ]);
        self::assertSame(1, $inserted, $this->database->last_error);
    }
}
