<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Assessments\Read\GetLibraryPublicAssessmentsService;
use Biblio\Core\Application\Catalog\Read\CatalogDataState;
use Biblio\Core\Application\Catalog\Read\CatalogItemNotAvailable;
use Biblio\Core\Application\Catalog\Read\CatalogOverviewPageSize;
use Biblio\Core\Application\Catalog\Read\CatalogUiReadService;
use Biblio\Core\Application\Catalog\Classification\Read\LibraryClassificationQueryService;
use Biblio\Core\Application\Collections\Read\LibraryCollectionQueryService;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbActorLibraryContextRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbCatalogUiReadRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbCollectionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryClassificationReadRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPublicationRepository;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Reading\PersonalWorkReadingStatus;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;

final class CatalogUiReadModelsTest extends PersistenceIntegrationTestCase
{
    public function testOverviewIsBoundedSortedScopedAndStatusAware(): void
    {
        $actor = new UserId("501");
        $this->seedLibrary("catalog-library", "Mijn Bibliotheek", $actor, "direct");
        $this->seedLibrary("foreign-library", "Andere Bibliotheek", new UserId("502"), "direct");
        $this->seedItem("item-alpha-b", "catalog-library", "work-alpha", "Alpha");
        $this->seedItem("item-alpha-a", "catalog-library", "work-alpha", "Alpha");
        $this->seedItem("item-reading", "catalog-library", "work-reading", "Lezen");
        $this->seedItem("item-stopped", "catalog-library", "work-stopped", "Stopgezet");
        $this->seedItem("item-read", "catalog-library", "work-read", "Uitgelezen");
        $this->seedItem("item-zulu", "catalog-library", "work-zulu", "Zulu");
        $this->seedItem("item-foreign", "foreign-library", "work-foreign", "Verborgen");
        $this->seedRound("round-active", $actor, "work-reading", "item-reading", null, "source_started");
        $this->seedRound("round-stopped", $actor, "work-stopped", "item-stopped", "stopped", "source_started");
        $this->seedRound("round-completed", $actor, "work-read", "item-read", "completed", "source_started");
        $this->seedRound("round-historical", $actor, "work-read", null, "completed", "historical_manual");
        $this->seedRound("round-foreign-reader", new UserId("502"), "work-alpha", null, "completed", "historical_manual");
        $service = $this->service($actor);
        $beforeQueries = $this->database->num_queries;

        $first = $service->activeOverview(
            new LibraryId("catalog-library"),
            null,
            new CatalogOverviewPageSize(3)
        );

        self::assertLessThanOrEqual(2, $this->database->num_queries - $beforeQueries);
        self::assertSame("Mijn Bibliotheek", $first->library()->name()->value());
        self::assertSame(
            ["item-alpha-a", "item-alpha-b", "item-reading"],
            array_map(static fn ($item): string => $item->itemId()->value(), $first->items())
        );
        self::assertNotNull($first->nextCursor());
        self::assertSame(PersonalWorkReadingStatus::Reading, $first->items()[2]->readingStatus());
        self::assertSame(PersonalWorkReadingStatus::NotRead, $first->items()[0]->readingStatus());
        self::assertFalse($first->items()[2]->capabilities()->canStartReading());
        self::assertSame(CatalogDataState::Unknown, $first->items()[0]->authors()->state());
        self::assertSame(CatalogDataState::Unknown, $first->items()[0]->coverReference()->state());
        self::assertSame("physical_book", $first->items()[0]->form()->value());
        self::assertSame("Mijn Bibliotheek", $first->items()[0]->locationOrSource()->value());

        $second = $service->activeOverview(
            new LibraryId("catalog-library"),
            $first->nextCursor(),
            new CatalogOverviewPageSize(3)
        );
        self::assertSame(
            ["item-stopped", "item-read", "item-zulu"],
            array_map(static fn ($item): string => $item->itemId()->value(), $second->items())
        );
        self::assertNull($second->nextCursor());
        self::assertSame(PersonalWorkReadingStatus::NotRead, $second->items()[0]->readingStatus());
        self::assertSame(PersonalWorkReadingStatus::Read, $second->items()[1]->readingStatus());
        self::assertNotContains(
            "item-foreign",
            array_map(static fn ($item): string => $item->itemId()->value(), $second->items())
        );
    }

    public function testDetailProjectsIdentityUnknownMetadataAndReadingSummary(): void
    {
        $actor = new UserId("503");
        $this->seedLibrary("detail-library", "Detailbibliotheek", $actor, "direct");
        $this->seedItem("detail-item", "detail-library", "detail-work", "Detailtitel");
        $this->seedRound("detail-completed", $actor, "detail-work", "detail-item", "completed", "source_started");
        $this->seedRound("detail-historical", $actor, "detail-work", null, "completed", "historical_manual");
        $this->seedRound("detail-stopped", $actor, "detail-work", "detail-item", "stopped", "source_started");

        $detail = $this->service($actor)->itemDetail(
            new LibraryId("detail-library"),
            new ItemId("detail-item")
        );

        self::assertSame("detail-library", $detail->library()->libraryId()->value());
        self::assertSame("detail-item", $detail->itemId()->value());
        self::assertSame("detail-work", $detail->workId()->value());
        self::assertSame("edition-detail-item", $detail->editionId()->value());
        self::assertSame("Detailtitel", $detail->title());
        self::assertSame(PersonalWorkReadingStatus::Read, $detail->reading()->status());
        self::assertSame(0, $detail->reading()->activeRounds());
        self::assertSame(2, $detail->reading()->completedRounds());
        self::assertSame(1, $detail->reading()->stoppedRounds());
        self::assertSame(1, $detail->reading()->historicalCompletedRounds());
        self::assertSame(CatalogDataState::Unknown, $detail->isbn()->state());
        self::assertSame(CatalogDataState::Unknown, $detail->language()->state());
        self::assertSame(CatalogDataState::Unknown, $detail->publisher()->state());
        self::assertSame(CatalogDataState::Unknown, $detail->publicationDate()->state());
        self::assertSame(CatalogDataState::Unknown, $detail->series()->state());
        self::assertSame(CatalogDataState::Unknown, $detail->location()->state());
        self::assertSame(CatalogDataState::Unknown, $detail->condition()->state());
        self::assertSame(CatalogDataState::Unknown, $detail->acquisition()->state());
        self::assertSame(CatalogDataState::Unknown, $detail->availability()->state());
        self::assertNull($detail->classification());
        self::assertTrue($detail->capabilities()->canStartReading());
        self::assertFalse($detail->capabilities()->canEndReading());
        self::assertNull($detail->activeReadingRound());
    }

    public function testOverviewAndDetailProjectOnlyTheActorsTruthWithDateQualifier(): void
    {
        $actor = new UserId("511");
        $other = new UserId("512");
        $library = new LibraryId("truth-view-library");
        $secondLibrary = new LibraryId("truth-second-library");
        $this->seedLibrary($library->value(), "Truth view", $actor, "direct");
        $this->seedLibrary($secondLibrary->value(), "Truth second", $actor, "direct");
        $this->seedItem("truth-read-item", $library->value(), "truth-read-work", "Read truth");
        $this->seedItem(
            "truth-read-second-item",
            $secondLibrary->value(),
            "truth-read-work",
            "Read truth second copy"
        );
        $this->seedItem("truth-unknown-item", $library->value(), "truth-unknown-work", "Unknown truth");
        $this->seedTruth($actor, "truth-read-work", "read_known_date_unknown");
        $this->seedTruth($actor, "truth-unknown-work", "unknown");
        $this->seedTruth($other, "truth-read-work", "explicit_not_read");

        $overview = $this->service($actor)->activeOverview($library);
        $byId = [];
        foreach ($overview->items() as $item) {
            $byId[$item->itemId()->value()] = $item;
        }
        self::assertSame(PersonalWorkReadingStatus::Read, $byId["truth-read-item"]->readingStatus());
        self::assertFalse($byId["truth-read-item"]->readDateKnown());
        self::assertSame(PersonalWorkReadingStatus::Unknown, $byId["truth-unknown-item"]->readingStatus());
        self::assertNull($byId["truth-unknown-item"]->readDateKnown());

        $detail = $this->service($actor)->itemDetail(
            $library,
            new ItemId("truth-read-item")
        );
        self::assertSame(PersonalWorkReadingStatus::Read, $detail->reading()->status());
        self::assertFalse($detail->reading()->readDateKnown());

        $secondOverview = $this->service($actor)->activeOverview($secondLibrary);
        self::assertCount(1, $secondOverview->items());
        self::assertSame(
            PersonalWorkReadingStatus::Read,
            $secondOverview->items()[0]->readingStatus()
        );
        self::assertFalse($secondOverview->items()[0]->readDateKnown());
        self::assertSame(1, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->personalReadingTruths()}` "
                . "WHERE user_id='511' AND work_id='truth-read-work'"
        ));
        self::assertSame(0, $detail->reading()->completedRounds());
    }

    public function testDetailClassificationUsesAssignedTermsForExactLibraryAndWork(): void
    {
        $actor = new UserId("510");
        $libraryA = new LibraryId("classification-library-a");
        $libraryB = new LibraryId("classification-library-b");
        $this->seedLibrary($libraryA->value(), "Classificatie A", $actor, "direct");
        $this->seedLibrary($libraryB->value(), "Classificatie B", $actor, "direct");
        $this->seedItem("classification-item-a", $libraryA->value(), "shared-work", "Gedeelde uitgave");
        $this->database->insert($this->tableNames->items(), [
            "item_id" => "classification-item-b",
            "library_id" => $libraryB->value(),
            "edition_id" => "edition-classification-item-a",
            "item_status" => "active",
        ]);

        $this->seedClassification(
            $libraryA->value(),
            "shared-work",
            ["book-a", "Leesboek", "leesboek", "active"],
            [
                ["genre-z", "Zed genre", "zed genre", "active"],
                ["genre-a", "Archiefgenre", "archiefgenre", "inactive"],
            ],
            [
                ["subject-b", "Zeer lang onderwerp dat rustig moet kunnen afbreken", "zeer lang onderwerp dat rustig moet kunnen afbreken", "active"],
                ["subject-a", "Aarde", "aarde", "active"],
            ]
        );
        $this->seedClassification(
            $libraryB->value(),
            "shared-work",
            ["book-b", "Naslagwerk", "naslagwerk", "active"],
            [["genre-b", "Detective", "detective", "active"]],
            []
        );

        $service = $this->service($actor);
        $detailA = $service->itemDetail(
            $libraryA,
            new ItemId("classification-item-a")
        );
        $detailB = $service->itemDetail(
            $libraryB,
            new ItemId("classification-item-b")
        );

        self::assertSame("book-a", $detailA->classification()?->bookType()->id()->value());
        self::assertSame(
            ["Archiefgenre", "Zed genre"],
            array_map(
                static fn ($term): string => $term->name()->value(),
                $detailA->classification()?->genres() ?? []
            )
        );
        self::assertSame(
            ["Aarde", "Zeer lang onderwerp dat rustig moet kunnen afbreken"],
            array_map(
                static fn ($term): string => $term->name()->value(),
                $detailA->classification()?->subjects() ?? []
            )
        );
        self::assertSame(
            "inactive",
            $detailA->classification()?->genres()[0]->status()->value
        );
        self::assertSame("book-b", $detailB->classification()?->bookType()->id()->value());
        self::assertSame(
            ["Detective"],
            array_map(
                static fn ($term): string => $term->name()->value(),
                $detailB->classification()?->genres() ?? []
            )
        );
    }

    public function testDetailCollectionsAreItemSpecificOrderedAndLibraryIsolated(): void
    {
        $actor = new UserId("511");
        $libraryA = new LibraryId("collection-detail-a");
        $libraryB = new LibraryId("collection-detail-b");
        $this->seedLibrary($libraryA->value(), "Collecties A", $actor, "direct");
        $this->seedLibrary($libraryB->value(), "Collecties B", $actor, "direct");
        $this->seedItem("collection-item-a", $libraryA->value(), "collection-work", "Gedeelde uitgave");
        $this->database->insert($this->tableNames->items(), [
            "item_id" => "collection-item-sibling",
            "library_id" => $libraryA->value(),
            "edition_id" => "edition-collection-item-a",
            "item_status" => "active",
        ]);
        $this->database->insert($this->tableNames->items(), [
            "item_id" => "collection-item-b",
            "library_id" => $libraryB->value(),
            "edition_id" => "edition-collection-item-a",
            "item_status" => "active",
        ]);
        $this->seedItem("collection-item-empty", $libraryA->value(), "empty-work", "Zonder collectie");

        $this->seedCollection($libraryA->value(), "collection-a-second", "Tweede collectie", 2);
        $this->seedCollection($libraryA->value(), "collection-a-first", "Eerste collectie", 1);
        $this->seedCollection($libraryA->value(), "collection-a-sibling", "Alleen ander exemplaar", 3);
        $this->seedCollection($libraryA->value(), "collection-a-archived", "Gearchiveerd", 4, "archived");
        $this->seedCollection($libraryA->value(), "collection-a-removed", "Verwijderd", 5);
        $this->seedCollection($libraryB->value(), "collection-b", "Andere Library", 1);
        $this->seedCollectionMembership($libraryA->value(), "membership-a-second", "collection-a-second", "collection-item-a", 1);
        $this->seedCollectionMembership($libraryA->value(), "membership-a-first", "collection-a-first", "collection-item-a", 1);
        $this->seedCollectionMembership($libraryA->value(), "membership-a-sibling", "collection-a-sibling", "collection-item-sibling", 1);
        $this->seedCollectionMembership($libraryA->value(), "membership-a-archived", "collection-a-archived", "collection-item-a", 1);
        $this->seedCollectionMembership($libraryA->value(), "membership-a-removed", "collection-a-removed", "collection-item-a", 1, "inactive");
        $this->seedCollectionMembership($libraryB->value(), "membership-b", "collection-b", "collection-item-b", 1);

        $service = $this->service($actor);
        $detailA = $service->itemDetail($libraryA, new ItemId("collection-item-a"));
        $sibling = $service->itemDetail($libraryA, new ItemId("collection-item-sibling"));
        $detailB = $service->itemDetail($libraryB, new ItemId("collection-item-b"));
        $empty = $service->itemDetail($libraryA, new ItemId("collection-item-empty"));

        self::assertSame(
            ["collection-a-first", "collection-a-second"],
            array_map(static fn ($collection): string => $collection->collectionId()->value(), $detailA->collections())
        );
        self::assertSame(
            ["Eerste collectie", "Tweede collectie"],
            array_map(static fn ($collection): string => $collection->displayName(), $detailA->collections())
        );
        self::assertSame(["collection-a-sibling"], array_map(
            static fn ($collection): string => $collection->collectionId()->value(),
            $sibling->collections()
        ));
        self::assertSame(["collection-b"], array_map(
            static fn ($collection): string => $collection->collectionId()->value(),
            $detailB->collections()
        ));
        self::assertSame([], $empty->collections());
    }

    public function testOverviewSortCursorAndDetailUseTheConcreteEditionTitle(): void
    {
        $actor = new UserId("509");
        $library = new LibraryId("title-library");
        $this->seedLibrary($library->value(), "Titelbibliotheek", $actor, "direct");
        $this->seedItem("title-zulu-item", $library->value(), "title-alpha-work", "Zulu Edition Title");
        $this->seedItem("title-alpha-item", $library->value(), "title-zulu-work", "Alpha Edition Title");
        $this->database->update(
            $this->tableNames->works(),
            ["work_title" => "Alpha Work Title"],
            ["work_id" => "title-alpha-work"]
        );
        $this->database->update(
            $this->tableNames->works(),
            ["work_title" => "Zulu Work Title"],
            ["work_id" => "title-zulu-work"]
        );

        $service = $this->service($actor);
        $first = $service->activeOverview(
            $library,
            null,
            new CatalogOverviewPageSize(1)
        );
        self::assertSame(["title-alpha-item"], array_map(
            static fn ($item): string => $item->itemId()->value(),
            $first->items()
        ));
        self::assertSame("Alpha Edition Title", $first->items()[0]->title());
        self::assertNotNull($first->nextCursor());

        $second = $service->activeOverview(
            $library,
            $first->nextCursor(),
            new CatalogOverviewPageSize(1)
        );
        self::assertSame(["title-zulu-item"], array_map(
            static fn ($item): string => $item->itemId()->value(),
            $second->items()
        ));
        self::assertSame("Zulu Edition Title", $second->items()[0]->title());
        self::assertNull($second->nextCursor());

        $detail = $service->itemDetail($library, new ItemId("title-alpha-item"));

        self::assertSame("Alpha Edition Title", $detail->title());
    }

    public function testDetailActiveRoundIsActorAndExactItemScoped(): void
    {
        $actor = new UserId("507");
        $other = new UserId("508");
        $library = new LibraryId("source-exact-library");
        $this->seedLibrary($library->value(), "Bronexact", $actor, "direct");
        $this->seedItem(
            "source-item-a",
            $library->value(),
            "source-work",
            "Zelfde Work"
        );
        $this->seedItem(
            "source-item-b",
            $library->value(),
            "source-work",
            "Zelfde Work"
        );
        $this->seedRound(
            "source-round-a",
            $actor,
            "source-work",
            "source-item-a",
            null,
            "source_started",
            null,
            7
        );
        $this->seedRound(
            "foreign-round-b",
            $other,
            "source-work",
            "source-item-b",
            null,
            "source_started"
        );
        $this->seedExternalLoan("source-loan", $actor, "source-work");
        $this->seedRound(
            "external-round",
            $actor,
            "source-work",
            null,
            null,
            "source_started",
            "source-loan"
        );

        $service = $this->service($actor);
        $itemA = $service->itemDetail($library, new ItemId("source-item-a"));
        $itemB = $service->itemDetail($library, new ItemId("source-item-b"));
        $active = $itemA->activeReadingRound();

        self::assertNotNull($active);
        self::assertSame("source-round-a", $active->readingRoundId()->value());
        self::assertSame(7, $active->version()->value());
        self::assertSame(2026, $active->startedOn()?->yearValue());
        self::assertSame(8, $active->startedOn()?->monthValue());
        self::assertSame(1, $active->startedOn()?->dayValue());
        self::assertTrue($itemA->capabilities()->canEndReading());
        self::assertFalse($itemA->capabilities()->canStartReading());

        self::assertNull($itemB->activeReadingRound());
        self::assertFalse($itemB->capabilities()->canEndReading());
        self::assertTrue($itemB->capabilities()->canStartReading());
        self::assertSame(PersonalWorkReadingStatus::Reading, $itemB->reading()->status());
        self::assertSame(2, $itemB->reading()->activeRounds());
    }

    public function testEmptyLibraryAndViewOnlyCapabilitiesAreExplicit(): void
    {
        $actor = new UserId("504");
        $this->seedLibrary("empty-library", "Leeg", $actor, "view_only");
        $empty = $this->service($actor)->activeOverview(new LibraryId("empty-library"));

        self::assertSame([], $empty->items());
        self::assertNull($empty->nextCursor());

        $this->seedItem("view-item", "empty-library", "view-work", "Alleen kijken");
        $view = $this->service($actor)->activeOverview(new LibraryId("empty-library"));
        self::assertTrue($view->items()[0]->capabilities()->canViewItem());
        self::assertFalse($view->items()[0]->capabilities()->canStartReading());
    }

    public function testUnknownAndCrossLibraryItemsFailIdentically(): void
    {
        $actor = new UserId("505");
        $other = new UserId("506");
        $this->seedLibrary("scope-library", "Scope", $actor, "direct");
        $this->seedLibrary("scope-foreign", "Foreign", $other, "direct");
        $this->seedLibrary("scope-inactive", "Inactive", $actor, "direct");
        $this->database->update(
            $this->tableNames->memberships(),
            ["membership_status" => "inactive"],
            ["library_id" => "scope-inactive", "user_id" => $actor->value()]
        );
        $this->seedItem("foreign-item", "scope-foreign", "foreign-work", "Foreign");
        $service = $this->service($actor);

        foreach (["foreign-item", "missing-item"] as $itemId) {
            try {
                $service->itemDetail(new LibraryId("scope-library"), new ItemId($itemId));
                self::fail("Unavailable Item was exposed.");
            } catch (CatalogItemNotAvailable $exception) {
                self::assertSame(
                    "Catalog Item is not available in this Library context.",
                    $exception->getMessage()
                );
            }
        }

        try {
            $service->activeOverview(new LibraryId("scope-foreign"));
            self::fail("Foreign Library context was exposed.");
        } catch (AuthorizationException $exception) {
            self::assertSame(
                "Library context is not available to the authenticated user.",
                $exception->getMessage()
            );
        }

        try {
            $service->activeOverview(new LibraryId("scope-inactive"));
            self::fail("Inactive Library context was exposed.");
        } catch (AuthorizationException $exception) {
            self::assertSame(
                "Library context is not available to the authenticated user.",
                $exception->getMessage()
            );
        }
    }

    public function testOverviewPlanUsesBoundedLibraryIndex(): void
    {
        $items = $this->tableNames->items();
        $editions = $this->tableNames->editions();
        $works = $this->tableNames->works();
        $plan = $this->database->get_results(
            "EXPLAIN SELECT i.item_id FROM `{$items}` i "
            . "FORCE INDEX (items_by_library) "
            . "INNER JOIN `{$editions}` e ON e.edition_id = i.edition_id "
            . "INNER JOIN `{$works}` w ON w.work_id = e.work_id "
            . "WHERE i.library_id = 'plan-library' "
            . "AND i.item_status = 'active' "
            . "ORDER BY w.work_title, i.item_id LIMIT 25",
            ARRAY_A
        );
        $itemStep = array_values(array_filter(
            $plan,
            static fn (array $step): bool => $step["table"] === "i"
        ));

        self::assertCount(1, $itemStep);
        self::assertSame("items_by_library", $itemStep[0]["key"]);
        self::assertLessThanOrEqual(25, (int) $itemStep[0]["rows"]);
    }

    private function service(UserId $actor): CatalogUiReadService
    {
        $authenticated = new ControllableAuthenticatedUser($actor);
        $contexts = new LibraryContextQueryService(
            $authenticated,
            new WpdbActorLibraryContextRepository($this->database, $this->tableNames),
            new LibraryAuthorizationPolicy()
        );

        return new CatalogUiReadService(
            $authenticated,
            $contexts,
            new WpdbCatalogUiReadRepository($this->database, $this->tableNames),
            new LibraryClassificationQueryService(
                $contexts,
                new WpdbLibraryClassificationReadRepository(
                    $this->database,
                    $this->tableNames
                )
            ),
            new LibraryCollectionQueryService(
                $contexts,
                new WpdbCollectionRepository($this->database, $this->tableNames)
            ),
            new GetLibraryPublicAssessmentsService(
                $contexts,
                new WpdbPublicationRepository($this->database, $this->tableNames)
            )
        );
    }

    private function seedCollection(
        string $libraryId,
        string $collectionId,
        string $name,
        int $position,
        string $status = "active"
    ): void {
        $this->database->insert($this->tableNames->collections(), [
            "library_id" => $libraryId,
            "collection_id" => $collectionId,
            "collection_name" => $name,
            "normalized_name" => strtolower($name),
            "collection_status" => $status,
            "collection_position" => $position,
            "collection_version" => 1,
            "created_at" => "2026-09-08 08:00:00.000000",
            "updated_at" => "2026-09-08 08:00:00.000000",
        ]);
    }

    private function seedCollectionMembership(
        string $libraryId,
        string $membershipId,
        string $collectionId,
        string $itemId,
        int $position,
        string $status = "active"
    ): void {
        $inactive = $status === "inactive";
        $this->database->insert($this->tableNames->collectionMemberships(), [
            "library_id" => $libraryId,
            "membership_id" => $membershipId,
            "collection_id" => $collectionId,
            "item_id" => $itemId,
            "membership_status" => $status,
            "item_position" => $position,
            "added_at" => "2026-09-08 08:01:00.000000",
            "ended_at" => $inactive ? "2026-09-08 08:02:00.000000" : null,
            "end_reason" => $inactive ? "removed" : null,
        ]);
    }

    private function seedLibrary(
        string $libraryId,
        string $name,
        UserId $userId,
        string $useAccess
    ): void {
        $this->database->insert($this->tableNames->libraries(), [
            "library_id" => $libraryId,
            "library_name" => $name,
            "library_type" => "private_library",
            "library_status" => "active",
        ]);
        $this->database->insert($this->tableNames->memberships(), [
            "library_id" => $libraryId,
            "user_id" => $userId->value(),
            "membership_status" => "active",
            "management_role" => $useAccess === "direct" ? "owner" : "member",
            "use_access" => $useAccess,
            "additional_permissions" => "[]",
        ]);
    }

    private function seedItem(
        string $itemId,
        string $libraryId,
        string $workId,
        string $title
    ): void {
        if ((int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$this->tableNames->works()}` WHERE work_id = %s",
            $workId
        )) === 0) {
            $this->database->insert($this->tableNames->works(), [
                "work_id" => $workId,
                "work_title" => $title,
            ]);
        }
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => "edition-{$itemId}",
            "work_id" => $workId,
            "edition_title" => $title,
        ]);
        $this->database->insert($this->tableNames->items(), [
            "item_id" => $itemId,
            "library_id" => $libraryId,
            "edition_id" => "edition-{$itemId}",
            "item_status" => "active",
        ]);
    }

    private function seedTruth(UserId $userId, string $workId, string $state): void
    {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->personalReadingTruths(),
            [
                "user_id" => $userId->value(),
                "work_id" => $workId,
                "truth_state" => $state,
                "truth_version" => 1,
                "created_at" => "2026-09-09 10:00:00.000000",
                "updated_at" => "2026-09-09 10:00:00.000000",
            ]
        ), $this->database->last_error);
    }

    /**
     * @param array{string, string, string, string} $bookType
     * @param list<array{string, string, string, string}> $genres
     * @param list<array{string, string, string, string}> $subjects
     */
    private function seedClassification(
        string $libraryId,
        string $workId,
        array $bookType,
        array $genres,
        array $subjects
    ): void {
        [$bookTypeId, $bookTypeName, $bookTypeNormalized, $bookTypeStatus] = $bookType;
        $this->database->insert($this->tableNames->libraryBookTypes(), [
            "library_id" => $libraryId,
            "book_type_id" => $bookTypeId,
            "display_name" => $bookTypeName,
            "normalized_name" => $bookTypeNormalized,
            "term_status" => $bookTypeStatus,
        ]);
        $this->database->insert($this->tableNames->libraryCatalogContexts(), [
            "library_id" => $libraryId,
            "work_id" => $workId,
            "book_type_id" => $bookTypeId,
            "context_version" => 1,
        ]);

        foreach ($genres as [$id, $name, $normalized, $status]) {
            $this->database->insert($this->tableNames->libraryGenres(), [
                "library_id" => $libraryId,
                "genre_id" => $id,
                "display_name" => $name,
                "normalized_name" => $normalized,
                "term_status" => $status,
            ]);
            $this->database->insert(
                $this->tableNames->libraryCatalogContextGenres(),
                ["library_id" => $libraryId, "work_id" => $workId, "genre_id" => $id]
            );
        }

        foreach ($subjects as [$id, $name, $normalized, $status]) {
            $this->database->insert($this->tableNames->librarySubjects(), [
                "library_id" => $libraryId,
                "subject_id" => $id,
                "display_name" => $name,
                "normalized_name" => $normalized,
                "term_status" => $status,
            ]);
            $this->database->insert(
                $this->tableNames->libraryCatalogContextSubjects(),
                ["library_id" => $libraryId, "work_id" => $workId, "subject_id" => $id]
            );
        }
    }

    private function seedRound(
        string $roundId,
        UserId $userId,
        string $workId,
        ?string $itemId,
        ?string $outcome,
        string $provenance,
        ?string $externalLoanId = null,
        int $version = 1
    ): void {
        $historical = $provenance === "historical_manual";
        $this->database->insert($this->tableNames->readingRounds(), [
            "reading_round_id" => $roundId,
            "user_id" => $userId->value(),
            "work_id" => $workId,
            "item_id" => $itemId,
            "external_loan_id" => $externalLoanId,
            "started_at" => null,
            "round_outcome" => $outcome,
            "provenance" => $provenance,
            "reading_started_year" => 2026,
            "reading_started_month" => 8,
            "reading_started_day" => 1,
            "reading_finished_year" => $outcome !== null ? 2026 : null,
            "reading_finished_month" => $outcome !== null ? 8 : null,
            "reading_finished_day" => $outcome !== null ? 2 : null,
            "created_at" => "2026-08-01 10:00:00.000000",
            "updated_at" => "2026-08-02 10:00:00.000000",
            "ended_at" => $outcome === null ? null : "2026-08-02 10:00:00.000000",
            "round_version" => $version,
        ]);
    }

    private function seedExternalLoan(
        string $loanId,
        UserId $userId,
        string $workId
    ): void {
        $this->database->insert($this->tableNames->externalLoans(), [
            "external_loan_id" => $loanId,
            "user_id" => $userId->value(),
            "work_id" => $workId,
            "loan_status" => "active",
            "borrowed_at" => "2026-08-01 10:00:00.000000",
            "due_at" => null,
        ]);
    }
}
