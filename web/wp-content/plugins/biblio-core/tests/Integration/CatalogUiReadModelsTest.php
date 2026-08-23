<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Catalog\Read\CatalogDataState;
use Biblio\Core\Application\Catalog\Read\CatalogItemNotAvailable;
use Biblio\Core\Application\Catalog\Read\CatalogOverviewPageSize;
use Biblio\Core\Application\Catalog\Read\CatalogUiReadService;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbActorLibraryContextRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbCatalogUiReadRepository;
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
        self::assertTrue($detail->capabilities()->canStartReading());
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
            new WpdbCatalogUiReadRepository($this->database, $this->tableNames)
        );
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
        ]);
        $this->database->insert($this->tableNames->items(), [
            "item_id" => $itemId,
            "library_id" => $libraryId,
            "edition_id" => "edition-{$itemId}",
            "item_status" => "active",
        ]);
    }

    private function seedRound(
        string $roundId,
        UserId $userId,
        string $workId,
        ?string $itemId,
        ?string $outcome,
        string $provenance
    ): void {
        $historical = $provenance === "historical_manual";
        $this->database->insert($this->tableNames->readingRounds(), [
            "reading_round_id" => $roundId,
            "user_id" => $userId->value(),
            "work_id" => $workId,
            "item_id" => $itemId,
            "external_loan_id" => null,
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
            "round_version" => 1,
        ]);
    }
}
