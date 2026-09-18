<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Identity\{PersonalMigrationTarget,PersonalMigrationTargetReadiness,PlatformUserDirectory};
use Biblio\Core\Application\Migration\{
    CommitMigrationRecordService,
    MappingDisposition,
    MigrationClock,
    MigrationMode,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationTargetMapping,
    ObserveSourceRecordService,
    SourceObservation
};
use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\Runner\{MigrationPlanningTarget,MigrationSourceInspection,MigrationSourcePackage,MigrationSourceProfile,MigrationSourceRecord,PreparedMigrationPlan,PreparedMigrationRecord};
use Biblio\Core\Application\Migration\Wishlist\{
    HistoricalWishlistRecorder,
    WishlistMigrationFailure,
    WishlistMigrationParticipant,
    WishlistMigrationReason,
    WishlistMigrationWriter,
    WishlistPlan
};
use Biblio\Core\Application\Wishlist\WishlistRecorder;
use Biblio\Core\Catalog\{Edition,EditionId,Work,WorkId};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    WpdbEditionRepository,
    WpdbLibraryRepository,
    WpdbMigrationLedgerRepository,
    WpdbTransactionManager,
    WpdbWishlistRepository,
    WpdbWorkRepository
};
use Biblio\Core\Infrastructure\Migration\CurrentV1SourceAdapter;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\{Library,LibraryId,LibraryName};
use Biblio\Core\Wishlist\{
    WishlistClock,
    WishlistEntry,
    WishlistEntryId,
    WishlistEntryIdGenerator,
    WishlistTargetType
};
use DateTimeImmutable;

final class WishlistMigrationTestClock implements MigrationClock, WishlistClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-18T08:00:00.123456+00:00");
    }
}

final class WishlistMigrationTestIds implements WishlistEntryIdGenerator
{
    private int $next = 0;

    public function __construct(private readonly string $suffix)
    {
    }

    public function next(): WishlistEntryId
    {
        return new WishlistEntryId(
            "migrated-wishlist-{$this->suffix}-" . ++$this->next
        );
    }
}

final readonly class WishlistMigrationTestUsers implements PlatformUserDirectory
{
    public function isActive(UserId $userId): bool
    {
        return !str_starts_with($userId->value(), "inactive-");
    }
}

final class WishlistMigrationParticipantTest extends PersistenceIntegrationTestCase
{
    public function testHistoricalWorkOnlyEntryPreservesOwnerWorkAndBothTimestamps(): void
    {
        $fixture = $this->fixture("historical");
        $this->mapWork($fixture, "work/source", "work-target");
        $record = $this->wish($fixture, "wish/source");
        $result = $this->apply($fixture, $record);
        $mapping = $result["outcome"]->mappings()[0];

        self::assertSame("wishlist_entry", $mapping->targetType());
        self::assertSame(MappingDisposition::Created, $mapping->disposition());
        self::assertSame([[
            "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "source_id" => "work/source",
        ]], $result["plan"]->dependencies());
        self::assertSame(
            [["operation" => "create_or_reuse_work_only_wishlist_entry"]],
            $result["plan"]->operations()
        );

        $stored = $fixture["wishlist"]->findForUser(
            new WishlistEntryId($mapping->targetId()),
            $fixture["user"]
        );
        self::assertNotNull($stored);
        self::assertSame("work-target", $stored->workId()->value());
        self::assertNull($stored->editionId());
        self::assertSame(WishlistTargetType::WorkOnly, $stored->targetType());
        self::assertSame(
            "2001-02-03 04:05:06.123456",
            $stored->createdAt()->format("Y-m-d H:i:s.u")
        );
        self::assertSame(
            "2002-03-04 05:06:07.654321",
            $stored->updatedAt()->format("Y-m-d H:i:s.u")
        );
        self::assertNotSame(
            $fixture["clock"]->now()->format("Y-m-d H:i:s.u"),
            $stored->createdAt()->format("Y-m-d H:i:s.u")
        );
    }

    public function testReplayAndEquivalentConvergenceReuseWithoutDuplicateIntent(): void
    {
        $fixture = $this->fixture("replay");
        $this->mapWork($fixture, "work/source", "work-target");
        $first = $this->wish($fixture, "wish/source-a");
        $firstId = $this->apply($fixture, $first)["outcome"]
            ->mappings()[0]->targetId();

        $fixture = $this->laterRun($fixture, "replay-second-run");
        $same = $this->apply($fixture, $first)["outcome"]->mappings()[0];
        self::assertSame($firstId, $same->targetId());
        self::assertSame(MappingDisposition::Reused, $same->disposition());

        $equivalent = $this->apply(
            $fixture,
            $this->wish($fixture, "wish/source-b")
        )["outcome"]->mappings()[0];
        self::assertSame($firstId, $equivalent->targetId());
        self::assertSame(MappingDisposition::Reused, $equivalent->disposition());
        self::assertSame(1, $this->rows($this->tableNames->wishlistEntries()));

        $fixture = $this->laterRun($fixture, "replay-divergent");
        $this->assertFailure(
            $fixture,
            $this->wish(
                $fixture,
                "wish/source-a",
                new DateTimeImmutable("2003-03-04T05:06:07.654321+00:00")
            ),
            WishlistMigrationReason::DivergentReplay
        );
        self::assertSame(1, $this->rows($this->tableNames->wishlistEntries()));
    }

    public function testEditionSpecificCollisionAndWrongOwnerFailClosed(): void
    {
        $fixture = $this->fixture("conflict");
        $this->mapWork($fixture, "work/source", "work-target");
        $fixture["editions"]->add(new Edition(
            new EditionId("edition-target"),
            new WorkId("work-target"),
            "Edition target"
        ));
        $fixture["transactions"]->run(function () use ($fixture): void {
            $now = $fixture["clock"]->now();
            $fixture["wishlist"]->lockOrCreateWorkState(
                $fixture["user"],
                new WorkId("work-target"),
                WishlistTargetType::EditionSpecific,
                $now
            );
            $fixture["wishlist"]->add(WishlistEntry::editionSpecific(
                new WishlistEntryId("existing-edition-wish"),
                $fixture["user"],
                new WorkId("work-target"),
                new EditionId("edition-target"),
                $now
            ));
        });
        $this->assertFailure(
            $fixture,
            $this->wish($fixture, "wish/work-only"),
            WishlistMigrationReason::CardinalityConflict
        );

        $wrongOwner = MigrationSourceRecord::typed(
            WishlistMigrationParticipant::SOURCE_TYPE,
            "wish/wrong-owner",
            new WishlistPlan(
                new UserId("another-user"),
                "work/source",
                new DateTimeImmutable("2001-02-03T04:05:06.123456+00:00"),
                new DateTimeImmutable("2002-03-04T05:06:07.654321+00:00"),
                "test-contract",
                str_repeat("a", 64),
                CurrentV1SourceAdapter::ADAPTER_ID,
                CurrentV1SourceAdapter::SOURCE_FAMILY,
                CurrentV1SourceAdapter::SOURCE_VERSION
            )
        );
        try {
            $fixture["participant"]->plan($wrongOwner, $fixture["target"]);
            self::fail("Wrong Wishlist owner was accepted.");
        } catch (WishlistMigrationFailure $failure) {
            self::assertSame(WishlistMigrationReason::OwnerMismatch, $failure->reason());
        }
    }

    public function testNormalInteractiveRecorderStillUsesItsClock(): void
    {
        $fixture = $this->fixture("interactive");
        $fixture["works"]->add(new Work(new WorkId("interactive-work"), "Interactive"));
        $recorder = new WishlistRecorder(
            new WishlistMigrationTestUsers(),
            $fixture["works"],
            $fixture["editions"],
            $fixture["wishlist"],
            new WishlistMigrationTestIds("interactive"),
            $fixture["clock"]
        );
        $result = $fixture["transactions"]->run(fn () =>
            $recorder->addWorkOnlyForOwner(
                $fixture["user"],
                new WorkId("interactive-work")
            )
        );
        self::assertEquals($fixture["clock"]->now(), $result->entry()->createdAt());
        self::assertEquals($fixture["clock"]->now(), $result->entry()->updatedAt());
    }

    public function testTargetInspectionRejectsChangedTimestampState(): void
    {
        $fixture = $this->fixture("inspection");
        $this->mapWork($fixture, "work/source", "work-target");
        $record = $this->wish($fixture, "wish/source");
        $applied = $this->apply($fixture, $record);
        $prepared = new PreparedMigrationPlan(
            new MigrationSourceInspection(
                new MigrationSourcePackage("/tmp", [], str_repeat("a", 64)),
                new CurrentV1SourceAdapter(),
                new MigrationSourceProfile(CurrentV1SourceAdapter::SOURCE_VERSION, []),
                [$record],
                []
            ),
            $fixture["target"],
            [new PreparedMigrationRecord(
                $record,
                $fixture["participant"],
                $applied["plan"]
            )],
            [],
            [],
            ["test-contract"],
            hash("sha256", "wishlist-inspection")
        );
        $reconciliation = (new ProductionComposition($this->database))
            ->application()
            ->migrationReconciliation();
        self::assertSame(
            0,
            $reconciliation->reconcile($fixture["run"], $prepared)
                ->brokenTargetCount()
        );

        $targetId = $applied["outcome"]->mappings()[0]->targetId();
        self::assertSame(1, $this->database->update(
            $this->tableNames->wishlistEntries(),
            ["updated_at" => "2003-03-04 05:06:07.654321"],
            ["wishlist_entry_id" => $targetId],
            ["%s"],
            ["%s"]
        ));
        self::assertSame(
            1,
            $reconciliation->reconcile($fixture["run"], $prepared)
                ->brokenTargetCount()
        );
    }

    /** @return array<string,mixed> */
    private function fixture(string $suffix): array
    {
        $library = new LibraryId("wishlist-migration-library-{$suffix}");
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(
            Library::privateLibrary($library, new LibraryName("Library {$suffix}"))
        );
        $user = new UserId("wishlist-migration-user-{$suffix}");
        $clock = new WishlistMigrationTestClock();
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $run = $ledger->beginOrResume(MigrationRun::start(
            "wishlist-migration-run-{$suffix}",
            CurrentV1SourceAdapter::SOURCE_FAMILY,
            str_repeat("a", 64),
            str_repeat("a", 64),
            CurrentV1SourceAdapter::SOURCE_VERSION,
            "2.46.0-" . substr(hash("sha256", $suffix), 0, 8),
            $user,
            $library,
            MigrationMode::Apply,
            $clock->now()
        ));
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $editions = new WpdbEditionRepository($this->database, $this->tableNames);
        $wishlist = new WpdbWishlistRepository($this->database, $this->tableNames);
        $transactions = new WpdbTransactionManager($this->database);
        $participant = new WishlistMigrationParticipant(new WishlistMigrationWriter(
            $ledger,
            new HistoricalWishlistRecorder(
                new WishlistMigrationTestUsers(),
                $works,
                $wishlist,
                new WishlistMigrationTestIds($suffix)
            )
        ));
        return $this->withRunServices([
            "library" => $library,
            "user" => $user,
            "clock" => $clock,
            "ledger" => $ledger,
            "run" => $run,
            "works" => $works,
            "editions" => $editions,
            "wishlist" => $wishlist,
            "transactions" => $transactions,
            "participant" => $participant,
        ]);
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function withRunServices(array $fixture): array
    {
        $fixture["target"] = new MigrationPlanningTarget(
            new PersonalMigrationTarget(
                $fixture["user"],
                $fixture["library"],
                new LibraryName("Migration target"),
                new PersonalMigrationTargetReadiness([])
            )
        );
        $fixture["observe"] = new ObserveSourceRecordService(
            $fixture["ledger"],
            $fixture["clock"]
        );
        $fixture["commit"] = new CommitMigrationRecordService(
            $fixture["ledger"],
            $fixture["transactions"],
            $fixture["clock"]
        );
        return $fixture;
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function laterRun(array $fixture, string $suffix): array
    {
        $fixture["ledger"]->releaseRunLock($fixture["run"]->id());
        $fixture["run"] = $fixture["ledger"]->beginOrResume(MigrationRun::start(
            "wishlist-migration-run-{$suffix}",
            CurrentV1SourceAdapter::SOURCE_FAMILY,
            str_repeat("a", 64),
            str_repeat("a", 64),
            CurrentV1SourceAdapter::SOURCE_VERSION,
            "2.46.0-" . substr(hash("sha256", $suffix), 0, 8),
            $fixture["user"],
            $fixture["library"],
            MigrationMode::Apply,
            $fixture["clock"]->now()
        ));
        return $this->withRunServices($fixture);
    }

    /** @param array<string,mixed> $fixture */
    private function mapWork(array $fixture, string $sourceId, string $targetId): void
    {
        $fixture["works"]->add(new Work(new WorkId($targetId), "Mapped Work"));
        $record = new MigrationSourceRecord(
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            ["approved_target_id" => $targetId]
        );
        $observation = $this->observe($fixture, $record);
        $fixture["commit"]->commit(
            $fixture["run"],
            $observation,
            static fn (): MigrationRecordOutcome => MigrationRecordOutcome::mapped([
                new MigrationTargetMapping(
                    "work",
                    $targetId,
                    MappingDisposition::Reused
                ),
            ])
        );
    }

    /** @param array<string,mixed> $fixture */
    private function wish(
        array $fixture,
        string $sourceId,
        ?DateTimeImmutable $updatedAt = null
    ): MigrationSourceRecord {
        return MigrationSourceRecord::typed(
            WishlistMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            new WishlistPlan(
                $fixture["user"],
                "work/source",
                new DateTimeImmutable("2001-02-03T04:05:06.123456+00:00"),
                $updatedAt ?? new DateTimeImmutable("2002-03-04T05:06:07.654321+00:00"),
                "test-contract",
                str_repeat("a", 64),
                CurrentV1SourceAdapter::ADAPTER_ID,
                CurrentV1SourceAdapter::SOURCE_FAMILY,
                CurrentV1SourceAdapter::SOURCE_VERSION
            )
        );
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function apply(array $fixture, MigrationSourceRecord $record): array
    {
        $plan = $fixture["participant"]->plan($record, $fixture["target"]);
        $observation = $this->observe($fixture, $record);
        $outcome = $fixture["commit"]->commit(
            $fixture["run"],
            $observation,
            fn (): MigrationRecordOutcome => $fixture["participant"]->apply(
                $record,
                $observation,
                $plan,
                $fixture["target"]
            )
        );
        return compact("plan", "observation", "outcome");
    }

    /** @param array<string,mixed> $fixture */
    private function assertFailure(
        array $fixture,
        MigrationSourceRecord $record,
        WishlistMigrationReason $reason
    ): void {
        try {
            $this->apply($fixture, $record);
            self::fail("Wishlist migration failure was not raised.");
        } catch (WishlistMigrationFailure $failure) {
            self::assertSame($reason, $failure->reason());
        }
    }

    /** @param array<string,mixed> $fixture */
    private function observe(
        array $fixture,
        MigrationSourceRecord $record
    ): SourceObservation {
        return $fixture["observe"]->observe(
            $fixture["run"],
            $record->sourceType(),
            $record->sourceId(),
            $record->payloadHash(),
            $record->payload()
        );
    }

    private function rows(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM `{$table}`");
    }
}
