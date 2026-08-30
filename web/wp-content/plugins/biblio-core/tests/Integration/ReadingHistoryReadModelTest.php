<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Reading\History\GetMyReadingHistoryForWorkService;
use Biblio\Core\Application\Reading\History\ReadingHistoryPageSize;
use Biblio\Core\Application\Reading\History\ReadingHistorySourceType;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingHistoryReadRepository;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRoundOutcome;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;

final class ReadingHistoryReadModelTest extends PersistenceIntegrationTestCase
{
    public function testEmptyAndUnknownWorkHistoryIsOneEmptyQuery(): void
    {
        $beforeQueries = $this->database->num_queries;

        $page = $this->service(new UserId("history-empty-actor"))->forWork(
            new WorkId("history-unknown-work")
        );

        self::assertSame([], $page->entries());
        self::assertNull($page->nextCursor());
        self::assertSame(1, $this->database->num_queries - $beforeQueries);
    }

    public function testOwnerAndWorkScopeIncludesEverySupportedSourcePath(): void
    {
        $actor = new UserId("601");
        $other = new UserId("602");
        $this->seedLibrary("history-shared-library", $actor, "manager");
        $this->seedWork("history-work", "History Work");
        $this->seedWork("history-other-work", "Other Work");
        $this->seedItem(
            "history-item-a",
            "history-edition-a",
            "history-shared-library",
            "history-work"
        );
        $this->seedItem(
            "history-item-b",
            "history-edition-b",
            "history-shared-library",
            "history-work"
        );
        $this->seedExternalLoan(
            "history-loan",
            $actor,
            "history-work"
        );
        $this->seedRound(
            "history-item-round-a",
            $actor,
            "history-work",
            ReadingRoundOutcome::Completed,
            "source_started",
            ReadingDate::exact(2025, 1, 1),
            ReadingDate::exact(2025, 1, 2),
            "history-item-a"
        );
        $this->seedRound(
            "history-item-round-b",
            $actor,
            "history-work",
            ReadingRoundOutcome::Stopped,
            "source_started",
            ReadingDate::exact(2025, 2, 1),
            ReadingDate::exact(2025, 2, 2),
            "history-item-b"
        );
        $this->seedRound(
            "history-loan-round",
            $actor,
            "history-work",
            ReadingRoundOutcome::Completed,
            "source_started",
            ReadingDate::exact(2025, 3, 1),
            ReadingDate::exact(2025, 3, 2),
            externalLoanId: "history-loan"
        );
        $this->seedRound(
            "history-manual-round",
            $actor,
            "history-work",
            ReadingRoundOutcome::Completed,
            "historical_manual",
            ReadingDate::month(2024, 4),
            ReadingDate::month(2024, 5)
        );
        $this->seedRound(
            "history-legacy-round",
            $actor,
            "history-work",
            ReadingRoundOutcome::Stopped,
            "legacy_source_started",
            null,
            ReadingDate::year(2023),
            legacyStartedAt: "2022-12-31 23:00:00.000000"
        );
        $this->seedRound(
            "history-active-round",
            $actor,
            "history-work",
            null,
            "source_started",
            ReadingDate::exact(2026, 1, 1),
            null,
            "history-item-a"
        );
        $this->seedRound(
            "history-foreign-owner-round",
            $other,
            "history-work",
            ReadingRoundOutcome::Completed,
            "historical_manual",
            null,
            ReadingDate::exact(2026, 8, 1)
        );
        $this->seedRound(
            "history-other-work-round",
            $actor,
            "history-other-work",
            ReadingRoundOutcome::Completed,
            "historical_manual",
            null,
            ReadingDate::exact(2026, 8, 2)
        );
        self::assertSame(8, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->readingRounds()}`"
        ));
        self::assertSame(5, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->readingRounds()}` "
            . "WHERE user_id = '601' AND work_id = 'history-work' "
            . "AND round_outcome IN ('completed', 'stopped')"
        ));

        $page = $this->service($actor)->forWork(
            new WorkId("history-work"),
            pageSize: new ReadingHistoryPageSize(50)
        );
        $entries = $page->entries();

        self::assertCount(5, $entries);
        self::assertSame([
            ReadingRoundOutcome::Completed,
            ReadingRoundOutcome::Stopped,
            ReadingRoundOutcome::Completed,
            ReadingRoundOutcome::Completed,
            ReadingRoundOutcome::Stopped,
        ], array_map(
            static fn ($entry): ReadingRoundOutcome => $entry->outcome(),
            $entries
        ));
        self::assertSame(3, count(array_filter(
            $entries,
            static fn ($entry): bool =>
                $entry->outcome() === ReadingRoundOutcome::Completed
        )));
        self::assertSame(2, count(array_filter(
            $entries,
            static fn ($entry): bool =>
                $entry->outcome() === ReadingRoundOutcome::Stopped
        )));
        self::assertSame(2, $this->sourceCount(
            $entries,
            ReadingHistorySourceType::LibraryItem
        ));
        self::assertSame(1, $this->sourceCount(
            $entries,
            ReadingHistorySourceType::ExternalLoan
        ));
        self::assertSame(2, $this->sourceCount(
            $entries,
            ReadingHistorySourceType::Unknown
        ));
        self::assertSame(1, count(array_filter(
            $entries,
            static fn ($entry): bool => $entry->historicalRegistration()
        )));
        $legacy = array_values(array_filter(
            $entries,
            static fn ($entry): bool =>
                $entry->outcome() === ReadingRoundOutcome::Stopped
                && $entry->sourceType() === ReadingHistorySourceType::Unknown
        ));
        self::assertCount(1, $legacy);
        self::assertNull($legacy[0]->startedOn());
        self::assertNull($page->nextCursor());
    }

    public function testDatePrecisionAndNewestFirstIntervalOrderArePreserved(): void
    {
        $actor = new UserId("603");
        $this->seedWork("history-date-work", "Date Work");
        $this->seedHistorical(
            "date-year",
            $actor,
            "history-date-work",
            ReadingDate::year(2025),
            ReadingDate::year(2025)
        );
        $this->seedHistorical(
            "date-month",
            $actor,
            "history-date-work",
            ReadingDate::month(2025, 1),
            ReadingDate::month(2025, 1)
        );
        $this->seedHistorical(
            "date-exact",
            $actor,
            "history-date-work",
            ReadingDate::exact(2025, 1, 1),
            ReadingDate::exact(2025, 1, 1)
        );
        $this->seedHistorical(
            "date-previous",
            $actor,
            "history-date-work",
            null,
            ReadingDate::exact(2024, 12, 31)
        );

        $entries = $this->service($actor)->forWork(
            new WorkId("history-date-work")
        )->entries();

        self::assertCount(4, $entries);
        self::assertSame(
            [null, 1, 1, 12],
            array_map(
                static fn ($entry): ?int =>
                    $entry->finishedOn()->monthValue(),
                $entries
            )
        );
        self::assertSame(
            [null, null, 1, 31],
            array_map(
                static fn ($entry): ?int => $entry->finishedOn()->dayValue(),
                $entries
            )
        );
        self::assertNull($entries[0]->startedOn()?->monthValue());
        self::assertNull($entries[1]->startedOn()?->dayValue());
        self::assertSame(1, $entries[2]->startedOn()?->dayValue());
    }

    public function testDefaultMaximumAndExactLimitContractsUseLimitPlusOne(): void
    {
        $actor = new UserId("604");
        $this->seedWork("history-limit-work", "Limit Work");

        for ($number = 1; $number <= 51; ++$number) {
            $this->seedHistorical(
                sprintf("limit-%02d", $number),
                $actor,
                "history-limit-work",
                null,
                ReadingDate::exact(2025, 1, $number <= 28 ? $number : 28)
            );
        }

        $default = $this->service($actor)->forWork(
            new WorkId("history-limit-work")
        );
        $maximum = $this->service($actor)->forWork(
            new WorkId("history-limit-work"),
            pageSize: new ReadingHistoryPageSize(50)
        );

        self::assertCount(10, $default->entries());
        self::assertNotNull($default->nextCursor());
        self::assertCount(50, $maximum->entries());
        self::assertNotNull($maximum->nextCursor());

        $this->resetCoreTables();
        $this->seedWork("history-exact-limit-work", "Exact Limit Work");

        for ($number = 1; $number <= 10; ++$number) {
            $this->seedHistorical(
                "exact-limit-{$number}",
                $actor,
                "history-exact-limit-work",
                null,
                ReadingDate::exact(2025, 2, $number)
            );
        }

        $exact = $this->service($actor)->forWork(
            new WorkId("history-exact-limit-work")
        );
        self::assertCount(10, $exact->entries());
        self::assertNull($exact->nextCursor());
    }

    public function testTiesAcrossPagesHaveNoDuplicatesOrSkips(): void
    {
        $actor = new UserId("605");
        $workId = new WorkId("history-tie-work");
        $this->seedWork($workId->value(), "Tie Work");

        for ($number = 1; $number <= 13; ++$number) {
            $this->seedHistorical(
                sprintf("tie-%02d", $number),
                $actor,
                $workId->value(),
                ReadingDate::exact(2025, 1, $number),
                ReadingDate::month(2025, 6)
            );
        }

        $service = $this->service($actor);
        $cursor = null;
        $startedDays = [];
        $pageSizes = [];

        do {
            $beforeQueries = $this->database->num_queries;
            $page = $service->forWork(
                $workId,
                $cursor,
                new ReadingHistoryPageSize(5)
            );
            self::assertSame(1, $this->database->num_queries - $beforeQueries);
            $pageSizes[] = count($page->entries());

            foreach ($page->entries() as $entry) {
                $startedDays[] = $entry->startedOn()?->dayValue();
            }

            $cursor = $page->nextCursor();
        } while ($cursor !== null);

        self::assertSame([5, 5, 3], $pageSizes);
        self::assertSame(range(13, 1), $startedDays);
        self::assertCount(13, array_unique($startedDays));
    }

    public function testLargeUnrelatedDatasetStaysActorWorkBoundedAndOneQuery(): void
    {
        $actor = new UserId("history-scale-actor");
        $this->seedWork("history-scale-target", "Scale Target");
        $this->seedWork("history-scale-other", "Scale Other");
        self::assertNotFalse($this->database->query(
            "SET max_recursive_iterations = 3000"
        ));
        $table = $this->tableNames->readingRounds();
        self::assertSame(2075, $this->database->query(
            "INSERT INTO `{$table}` (reading_round_id, user_id, work_id, "
            . "item_id, external_loan_id, started_at, round_outcome, "
            . "provenance, reading_started_year, reading_started_month, "
            . "reading_started_day, reading_finished_year, "
            . "reading_finished_month, reading_finished_day, created_at, "
            . "updated_at, ended_at, round_version) "
            . "WITH RECURSIVE seq AS (SELECT 1 AS n UNION ALL "
            . "SELECT n + 1 FROM seq WHERE n < 2075) "
            . "SELECT CONCAT('scale-round-', LPAD(n, 4, '0')), "
            . "CASE WHEN n <= 575 THEN 'history-scale-actor' "
            . "ELSE CONCAT('scale-other-user-', MOD(n, 100)) END, "
            . "CASE WHEN n <= 75 OR (n > 575 AND n <= 1075) "
            . "THEN 'history-scale-target' ELSE 'history-scale-other' END, "
            . "NULL, NULL, NULL, IF(MOD(n, 2) = 0, 'completed', 'stopped'), "
            . "'historical_manual', NULL, NULL, NULL, "
            . "YEAR(DATE_ADD('2020-01-01', INTERVAL n DAY)), "
            . "MONTH(DATE_ADD('2020-01-01', INTERVAL n DAY)), "
            . "DAY(DATE_ADD('2020-01-01', INTERVAL n DAY)), "
            . "'2026-08-30 12:00:00.000000', "
            . "'2026-08-30 12:00:00.000000', "
            . "'2026-08-30 12:00:00.000000', 1 FROM seq"
        ));
        $activityBefore = (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->libraryActivityEvents()}`"
        );
        $service = $this->service($actor);
        $beforeQueries = $this->database->num_queries;

        $first = $service->forWork(
            new WorkId("history-scale-target"),
            pageSize: new ReadingHistoryPageSize(10)
        );

        self::assertSame(1, $this->database->num_queries - $beforeQueries);
        self::assertCount(10, $first->entries());
        self::assertNotNull($first->nextCursor());
        self::assertSame(
            $activityBefore,
            (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tableNames->libraryActivityEvents()}`"
            )
        );

        $plan = $this->historyExplain("history-scale-actor", "history-scale-target");
        self::assertSame(
            "reading_rounds_by_user_work_finish",
            $plan[0]["key"]
        );
        self::assertContains($plan[0]["type"], ["range", "ref"]);
        self::assertLessThanOrEqual(75, (int) $plan[0]["rows"]);
        self::assertStringContainsString("Using filesort", $plan[0]["Extra"]);

        $cursor = null;
        $dates = [];

        do {
            $beforePageQueries = $this->database->num_queries;
            $page = $service->forWork(
                new WorkId("history-scale-target"),
                $cursor,
                new ReadingHistoryPageSize(10)
            );
            self::assertSame(
                1,
                $this->database->num_queries - $beforePageQueries
            );

            foreach ($page->entries() as $entry) {
                $dates[] = sprintf(
                    "%04d-%02d-%02d",
                    $entry->finishedOn()->yearValue(),
                    $entry->finishedOn()->monthValue(),
                    $entry->finishedOn()->dayValue()
                );
            }

            $cursor = $page->nextCursor();
        } while ($cursor !== null);

        self::assertCount(75, $dates);
        self::assertCount(75, array_unique($dates));
    }

    public function testProductionCompositionExposesOnlyTheNamedService(): void
    {
        $application = (new ProductionComposition($this->database))->application();

        self::assertInstanceOf(
            GetMyReadingHistoryForWorkService::class,
            $application->readingHistory()
        );
    }

    private function service(UserId $actor): GetMyReadingHistoryForWorkService
    {
        return new GetMyReadingHistoryForWorkService(
            new ControllableAuthenticatedUser($actor),
            new WpdbReadingHistoryReadRepository(
                $this->database,
                $this->tableNames
            )
        );
    }

    private function seedWork(string $workId, string $title): void
    {
        self::assertSame(1, $this->database->insert($this->tableNames->works(), [
            "work_id" => $workId,
            "work_title" => $title,
        ]), $this->database->last_error);
    }

    private function seedLibrary(
        string $libraryId,
        UserId $actor,
        string $role
    ): void {
        self::assertSame(1, $this->database->insert($this->tableNames->libraries(), [
            "library_id" => $libraryId,
            "library_name" => "History Library",
            "library_type" => "private_library",
            "library_status" => "active",
        ]), $this->database->last_error);
        self::assertSame(1, $this->database->insert($this->tableNames->memberships(), [
            "library_id" => $libraryId,
            "user_id" => $actor->value(),
            "membership_status" => "active",
            "management_role" => $role,
            "use_access" => "view_only",
            "additional_permissions" => "[]",
        ]), $this->database->last_error);
    }

    private function seedItem(
        string $itemId,
        string $editionId,
        string $libraryId,
        string $workId
    ): void {
        self::assertSame(1, $this->database->insert($this->tableNames->editions(), [
            "edition_id" => $editionId,
            "work_id" => $workId,
        ]), $this->database->last_error);
        self::assertSame(1, $this->database->insert($this->tableNames->items(), [
            "item_id" => $itemId,
            "library_id" => $libraryId,
            "edition_id" => $editionId,
            "item_status" => "active",
        ]), $this->database->last_error);
    }

    private function seedExternalLoan(
        string $loanId,
        UserId $actor,
        string $workId
    ): void {
        self::assertSame(1, $this->database->insert($this->tableNames->externalLoans(), [
            "external_loan_id" => $loanId,
            "user_id" => $actor->value(),
            "work_id" => $workId,
            "loan_status" => "active",
            "borrowed_at" => "2025-01-01 10:00:00.000000",
            "due_at" => null,
        ]), $this->database->last_error);
    }

    private function seedHistorical(
        string $roundId,
        UserId $actor,
        string $workId,
        ?ReadingDate $startedOn,
        ReadingDate $finishedOn
    ): void {
        $this->seedRound(
            $roundId,
            $actor,
            $workId,
            ReadingRoundOutcome::Completed,
            "historical_manual",
            $startedOn,
            $finishedOn
        );
    }

    private function seedRound(
        string $roundId,
        UserId $actor,
        string $workId,
        ?ReadingRoundOutcome $outcome,
        string $provenance,
        ?ReadingDate $startedOn,
        ?ReadingDate $finishedOn,
        ?string $itemId = null,
        ?string $externalLoanId = null,
        ?string $legacyStartedAt = null
    ): void {
        $legacy = $provenance === "legacy_source_started";
        self::assertSame(1, $this->database->insert(
            $this->tableNames->readingRounds(),
            [
                "reading_round_id" => $roundId,
                "user_id" => $actor->value(),
                "work_id" => $workId,
                "item_id" => $itemId,
                "external_loan_id" => $externalLoanId,
                "started_at" => $legacyStartedAt,
                "round_outcome" => $outcome?->value,
                "provenance" => $provenance,
                "reading_started_year" => $startedOn?->yearValue(),
                "reading_started_month" => $startedOn?->monthValue(),
                "reading_started_day" => $startedOn?->dayValue(),
                "reading_finished_year" => $finishedOn?->yearValue(),
                "reading_finished_month" => $finishedOn?->monthValue(),
                "reading_finished_day" => $finishedOn?->dayValue(),
                "created_at" => $legacy
                    ? null
                    : "2025-01-01 10:00:00.000000",
                "updated_at" => $legacy
                    ? null
                    : "2025-01-02 10:00:00.000000",
                "ended_at" => $outcome === null
                    ? null
                    : "2025-01-02 10:00:00.000000",
                "round_version" => 1,
            ]
        ), $this->database->last_error);
    }

    /**
     * @param list<\Biblio\Core\Application\Reading\History\ReadingHistoryEntry> $entries
     */
    private function sourceCount(
        array $entries,
        ReadingHistorySourceType $sourceType
    ): int {
        return count(array_filter(
            $entries,
            static fn ($entry): bool => $entry->sourceType() === $sourceType
        ));
    }

    /** @return list<array<string, string|null>> */
    private function historyExplain(string $userId, string $workId): array
    {
        $table = $this->tableNames->readingRounds();

        return $this->database->get_results($this->database->prepare(
            "EXPLAIN SELECT reading_round_id FROM `{$table}` "
            . "WHERE user_id = %s AND work_id = %s "
            . "AND round_outcome IN ('completed', 'stopped') "
            . "ORDER BY reading_finished_year * 10000 "
            . "+ COALESCE(reading_finished_month, 1) * 100 "
            . "+ COALESCE(reading_finished_day, 1) DESC, "
            . "reading_finished_year * 10000 "
            . "+ COALESCE(reading_finished_month, 12) * 100 "
            . "+ COALESCE(reading_finished_day, DAYOFMONTH(LAST_DAY(CONCAT("
            . "reading_finished_year, '-', "
            . "COALESCE(reading_finished_month, 12), '-01')))) DESC, "
            . "reading_round_id DESC LIMIT 11",
            $userId,
            $workId
        ), ARRAY_A);
    }
}
