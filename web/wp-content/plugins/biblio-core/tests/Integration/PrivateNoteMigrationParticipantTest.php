<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Identity\{
    AuthenticatedUser,
    PersonalMigrationTarget,
    PersonalMigrationTargetReadiness,
    PlatformUserDirectory
};
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
use Biblio\Core\Application\Migration\Notes\{
    PrivateNoteMigrationFailure,
    PrivateNoteMigrationParticipant,
    PrivateNoteMigrationReason,
    PrivateNoteMigrationWriter,
    PrivateNotePlan
};
use Biblio\Core\Application\Migration\Reading\ReadingRoundMigrationParticipant;
use Biblio\Core\Application\Migration\Runner\{
    MigrationPlanningTarget,
    MigrationSourceRecord
};
use Biblio\Core\Application\Notes\{
    PrivateNoteCreation,
    Read\GetMyPrivateNotesForWorkService,
    RenderPrivateNoteContentService
};
use Biblio\Core\Catalog\{Work,WorkId};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    WpdbLibraryRepository,
    WpdbMigrationLedgerRepository,
    WpdbPrivateNoteRepository,
    WpdbReadingRoundRepository,
    WpdbTransactionManager,
    WpdbWorkRepository
};
use Biblio\Core\Library\{Library,LibraryId,LibraryName};
use Biblio\Core\Notes\{
    PrivateNoteContent,
    PrivateNoteId,
    PrivateNoteIdGenerator,
    PrivateNotePageRequest,
    StrictPrivateNoteContentPolicy
};
use Biblio\Core\Reading\{
    ReadingDate,
    ReadingPeriod,
    ReadingRound,
    ReadingRoundId
};
use DateTimeImmutable;

final class PrivateNoteMigrationTestClock implements MigrationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-15T13:00:00.123456+00:00");
    }
}

final class PrivateNoteMigrationTestIds implements PrivateNoteIdGenerator
{
    private int $next = 0;

    public function __construct(private readonly string $suffix)
    {
    }

    public function next(): PrivateNoteId
    {
        return new PrivateNoteId(
            "migrated-note-{$this->suffix}-" . ++$this->next
        );
    }
}

final readonly class PrivateNoteMigrationTestUsers implements PlatformUserDirectory
{
    public function isActive(UserId $userId): bool
    {
        return !str_starts_with($userId->value(), "inactive-");
    }
}

final readonly class PrivateNoteMigrationAuthenticatedUser implements AuthenticatedUser
{
    public function __construct(private UserId $userId)
    {
    }

    public function requireUserId(): UserId { return $this->userId; }
}

final class PrivateNoteMigrationParticipantTest extends PersistenceIntegrationTestCase
{
    public function testWorkOnlyNotesPreserveContentTimeAndDistinctSourceIdentity(): void
    {
        $fixture = $this->fixture("work-only");
        $this->mapWork($fixture, "work/source", "work-target");
        $first = $this->note($fixture, "note/first");
        $second = $this->note($fixture, "note/second");

        $firstResult = $this->apply($fixture, $first);
        $secondResult = $this->apply($fixture, $second);
        $firstId = $firstResult["outcome"]->mappings()[0]->targetId();
        $secondId = $secondResult["outcome"]->mappings()[0]->targetId();

        self::assertNotSame($firstId, $secondId);
        self::assertSame(2, $this->rows($this->tableNames->privateNotes()));
        self::assertSame([[
            "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "source_id" => "work/source",
        ]], $firstResult["plan"]->dependencies());
        self::assertArrayNotHasKey("typed_plan", $firstResult["plan"]->toArray());

        $stored = $fixture["notes"]->findForUser(
            new PrivateNoteId($firstId),
            $fixture["user"]
        );
        self::assertNotNull($stored);
        self::assertNull($stored->readingRoundId());
        self::assertSame("<p>Unicode: café 📚</p>", $stored->content()->value());
        self::assertSame(
            "2001-02-03 04:05:06.123456",
            $stored->createdAt()->format("Y-m-d H:i:s.u")
        );
        self::assertSame(
            "2002-03-04 05:06:07.654321",
            $stored->updatedAt()->format("Y-m-d H:i:s.u")
        );

        $fixture = $this->laterRun($fixture, "work-only-replay");
        $replay = $this->apply($fixture, $first)["outcome"];
        self::assertSame($firstId, $replay->mappings()[0]->targetId());
        self::assertSame(
            MappingDisposition::Reused,
            $replay->mappings()[0]->disposition()
        );
        self::assertSame(2, $this->rows($this->tableNames->privateNotes()));

        $fixture = $this->laterRun($fixture, "work-only-changed");
        $changed = $this->note(
            $fixture,
            "note/first",
            content: "<p>Gewijzigd</p>"
        );
        try {
            $this->apply($fixture, $changed);
            self::fail("Changed Private Note payload was silently reused.");
        } catch (PrivateNoteMigrationFailure $failure) {
            self::assertSame(
                PrivateNoteMigrationReason::DivergentReplay,
                $failure->reason()
            );
        }
        self::assertSame(2, $this->rows($this->tableNames->privateNotes()));
    }

    public function testExactRoundDependencyAcceptsOnlySameOwnerAndWork(): void
    {
        $fixture = $this->fixture("round");
        $this->mapWork($fixture, "work/source", "work-target");
        $this->mapWork($fixture, "work/other", "work-other");
        $this->mapRound(
            $fixture,
            "round/source",
            "round-target",
            $fixture["user"],
            new WorkId("work-target")
        );
        $linked = $this->apply($fixture, $this->note(
            $fixture,
            "note/linked",
            roundSourceId: "round/source"
        ));
        self::assertSame([
            [
                "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
                "source_id" => "work/source",
            ],
            [
                "source_type" => ReadingRoundMigrationParticipant::SOURCE_TYPE,
                "source_id" => "round/source",
            ],
        ], $linked["plan"]->dependencies());
        $stored = $fixture["notes"]->findForUser(
            new PrivateNoteId($linked["outcome"]->mappings()[0]->targetId()),
            $fixture["user"]
        );
        self::assertSame("round-target", $stored?->readingRoundId()?->value());

        $this->assertFailure(
            $fixture,
            $this->note(
                $fixture,
                "note/missing-round",
                roundSourceId: "round/missing"
            ),
            PrivateNoteMigrationReason::MissingReadingRoundMapping
        );

        $this->mapRound(
            $fixture,
            "round/foreign",
            "round-foreign",
            new UserId("another-user"),
            new WorkId("work-target")
        );
        $this->assertFailure(
            $fixture,
            $this->note(
                $fixture,
                "note/foreign-round",
                roundSourceId: "round/foreign"
            ),
            PrivateNoteMigrationReason::OwnerMismatch
        );

        $this->mapRound(
            $fixture,
            "round/other-work",
            "round-other-work",
            $fixture["user"],
            new WorkId("work-other")
        );
        $this->assertFailure(
            $fixture,
            $this->note(
                $fixture,
                "note/other-work",
                roundSourceId: "round/other-work"
            ),
            PrivateNoteMigrationReason::WorkRoundMismatch
        );
        self::assertSame(1, $this->rows($this->tableNames->privateNotes()));
    }

    public function testOwnershipPrivacyNormalReadAndReadZeroWriteArePreserved(): void
    {
        $fixture = $this->fixture("privacy");
        $this->mapWork($fixture, "work/source", "work-target");
        $result = $this->apply($fixture, $this->note(
            $fixture,
            "note/private"
        ));
        $id = new PrivateNoteId(
            $result["outcome"]->mappings()[0]->targetId()
        );

        self::assertNotNull($fixture["notes"]->findForUser($id, $fixture["user"]));
        self::assertNull($fixture["notes"]->findForUser(
            $id,
            new UserId("another-user")
        ));

        $before = $this->allCoreTableCounts();
        $ownerPage = $this->normalRead($fixture, $fixture["user"]);
        $otherPage = $this->normalRead(
            $fixture,
            new UserId("another-user")
        );
        self::assertCount(1, $ownerPage->notes());
        self::assertSame("<p>Unicode: café 📚</p>", $ownerPage->notes()[0]->contentHtml());
        self::assertSame([], $otherPage->notes());
        self::assertSame($before, $this->allCoreTableCounts());

        $wrongTargetRecord = MigrationSourceRecord::typed(
            PrivateNoteMigrationParticipant::SOURCE_TYPE,
            "note/wrong-user",
            new PrivateNotePlan(
                new UserId("another-user"),
                "work/source",
                $fixture["policy"]->sanitize("<p>Privé</p>"),
                new DateTimeImmutable("2001-01-01T00:00:00+00:00"),
                new DateTimeImmutable("2001-01-01T00:00:00+00:00")
            )
        );
        $this->assertPlanningFailure(
            $fixture,
            $wrongTargetRecord,
            PrivateNoteMigrationReason::OwnerMismatch
        );
    }

    public function testInvalidContentAndDependencyTypesFailWithoutLeakingContent(): void
    {
        $fixture = $this->fixture("invalid");
        foreach ([
            '<p onclick="secret()">do-not-log-private-body</p>',
            "<p> </p>",
            "<p>" . str_repeat("x", 65_535) . "</p>",
        ] as $index => $privateBody) {
            $invalid = MigrationSourceRecord::typed(
                PrivateNoteMigrationParticipant::SOURCE_TYPE,
                "note/invalid-content-{$index}",
                new PrivateNotePlan(
                    $fixture["user"],
                    "work/source",
                    new PrivateNoteContent($privateBody),
                    new DateTimeImmutable("2001-01-01T00:00:00+00:00"),
                    new DateTimeImmutable("2001-01-01T00:00:00+00:00")
                )
            );
            try {
                $fixture["participant"]->plan($invalid, $fixture["target"]);
                self::fail("Invalid Private Note content was accepted.");
            } catch (PrivateNoteMigrationFailure $failure) {
                self::assertSame(
                    PrivateNoteMigrationReason::InvalidNoteContent,
                    $failure->reason()
                );
                if (str_contains($privateBody, "do-not-log-private-body")) {
                    self::assertStringNotContainsString(
                        "do-not-log-private-body",
                        $failure->getMessage()
                    );
                }
            }
        }

        $this->assertFailure(
            $fixture,
            $this->note($fixture, "note/missing-work"),
            PrivateNoteMigrationReason::MissingWorkMapping
        );
        $this->map(
            $fixture,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "work/source",
            "edition",
            "not-a-work"
        );
        $this->assertFailure(
            $fixture,
            $this->note($fixture, "note/wrong-work-type"),
            PrivateNoteMigrationReason::WrongMappingTargetType
        );
        self::assertSame(0, $this->rows($this->tableNames->privateNotes()));
    }

    public function testNoteMappingConflictsAndMutatedReplayFailClosed(): void
    {
        $fixture = $this->fixture("mapping-conflict");
        $this->mapWork($fixture, "work/source", "work-target");
        $record = $this->note($fixture, "note/source-a");
        $result = $this->apply($fixture, $record);
        $targetId = $result["outcome"]->mappings()[0]->targetId();

        $fixture["transactions"]->run(function () use ($fixture, $targetId): void {
            $stored = $fixture["notes"]->findForUserForUpdate(
                new PrivateNoteId($targetId),
                $fixture["user"]
            );
            self::assertNotNull($stored);
            self::assertTrue($fixture["notes"]->replaceIfVersionMatches(
                $fixture["user"],
                $stored->replaceContent(
                    $fixture["policy"]->sanitize("<p>User edit</p>"),
                    new DateTimeImmutable("2003-01-01T00:00:00+00:00")
                ),
                $stored->version()
            ));
        });
        $fixture = $this->laterRun($fixture, "mutated-replay");
        $this->assertFailure(
            $fixture,
            $record,
            PrivateNoteMigrationReason::DivergentReplay
        );

        $exact = $this->note($fixture, "note/source-c", content: "<p>Exact</p>");
        $exactTargetId = $this->apply($fixture, $exact)["outcome"]
            ->mappings()[0]->targetId();
        $fixture = $this->laterRun($fixture, "reverse-conflict");
        $other = $this->note($fixture, "note/source-b", content: "<p>Exact</p>");
        $otherObservation = $this->observe($fixture, $other);
        $fixture["commit"]->commit(
            $fixture["run"],
            $otherObservation,
            static fn (): MigrationRecordOutcome => MigrationRecordOutcome::mapped([
                new MigrationTargetMapping(
                    "private_note",
                    $exactTargetId,
                    MappingDisposition::Reused
                ),
            ])
        );
        $this->assertFailure(
            $fixture,
            $exact,
            PrivateNoteMigrationReason::ConflictingNoteMapping
        );
        self::assertSame(2, $this->rows($this->tableNames->privateNotes()));
    }

    public function testSameSourceIdentityDoesNotReuseAcrossMigrationTargets(): void
    {
        $first = $this->fixture("target-a");
        $this->mapWork($first, "work/source", "work-target-a");
        $firstTarget = $this->apply(
            $first,
            $this->note($first, "note/shared-source")
        )["outcome"]->mappings()[0]->targetId();

        $second = $this->fixture("target-b");
        $this->mapWork($second, "work/source", "work-target-b");
        $secondTarget = $this->apply(
            $second,
            $this->note($second, "note/shared-source")
        )["outcome"]->mappings()[0]->targetId();

        self::assertNotSame($firstTarget, $secondTarget);
        self::assertSame(2, $this->rows($this->tableNames->privateNotes()));
    }

    public function testProductMappingAndLateOutcomeFailuresRollbackAtomically(): void
    {
        foreach ([
            "note" => [
                $this->tableNames->privateNotes(),
                "note_migration_note_write_failure",
            ],
            "mapping" => [
                $this->tableNames->migrationTargetMappings(),
                "note_migration_mapping_write_failure",
            ],
            "late outcome" => [
                $this->tableNames->migrationSourceObservations(),
                "note_migration_late_outcome_failure",
            ],
        ] as $suffix => [$table, $trigger]) {
            $fixture = $this->fixture(str_replace(" ", "-", $suffix));
            $this->mapWork(
                $fixture,
                "work/source",
                "work-target-" . str_replace(" ", "-", $suffix)
            );
            $record = $this->note($fixture, "note/rollback-{$suffix}");
            $plan = $fixture["participant"]->plan($record, $fixture["target"]);
            $observation = $this->observe($fixture, $record);
            $mappingsBefore = $this->rows(
                $this->tableNames->migrationTargetMappings()
            );
            $event = $suffix === "late outcome" ? "UPDATE" : "INSERT";
            self::assertNotFalse($this->database->query(
                "CREATE TRIGGER `{$trigger}` BEFORE {$event} ON `{$table}` "
                    . "FOR EACH ROW SIGNAL SQLSTATE '45000' "
                    . "SET MESSAGE_TEXT='injected Private Note migration failure'"
            ));
            $previousSuppression = $this->database->suppress_errors(true);
            try {
                try {
                    $fixture["commit"]->commit(
                        $fixture["run"],
                        $observation,
                        fn (): MigrationRecordOutcome => $fixture["participant"]->apply(
                            $record,
                            $observation,
                            $plan,
                            $fixture["target"]
                        )
                    );
                    self::fail("Injected {$suffix} failure was hidden.");
                } catch (PersistenceException) {
                }
            } finally {
                $this->database->suppress_errors($previousSuppression);
                $this->database->query("DROP TRIGGER IF EXISTS `{$trigger}`");
            }

            self::assertSame(0, $this->rows($this->tableNames->privateNotes()));
            self::assertSame(
                $mappingsBefore,
                $this->rows($this->tableNames->migrationTargetMappings())
            );
            self::assertSame("observed", $this->database->get_var(
                $this->database->prepare(
                    "SELECT processing_status FROM `{$this->tableNames->migrationSourceObservations()}` WHERE observation_id=%s",
                    $observation->id()
                )
            ));
        }
    }

    /** @return array<string,mixed> */
    private function fixture(string $suffix): array
    {
        $library = new LibraryId("note-migration-library-{$suffix}");
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(
            Library::privateLibrary($library, new LibraryName("Library {$suffix}"))
        );
        $user = new UserId("note-migration-user-{$suffix}");
        $clock = new PrivateNoteMigrationTestClock();
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $run = $ledger->beginOrResume(MigrationRun::start(
            "note-migration-run-{$suffix}",
            "synthetic",
            "snapshot-{$suffix}",
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.32.0",
            $user,
            $library,
            MigrationMode::Apply,
            $clock->now()
        ));
        $policy = new StrictPrivateNoteContentPolicy();
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $rounds = new WpdbReadingRoundRepository($this->database, $this->tableNames);
        $notes = new WpdbPrivateNoteRepository(
            $this->database,
            $this->tableNames,
            $policy
        );
        $transactions = new WpdbTransactionManager($this->database);
        $writer = new PrivateNoteMigrationWriter(
            $ledger,
            new PrivateNoteMigrationTestUsers(),
            $works,
            $rounds,
            $notes,
            new PrivateNoteCreation(
                new PrivateNoteMigrationTestIds($suffix),
                $notes
            ),
            $policy
        );
        $fixture = [
            "library" => $library,
            "user" => $user,
            "clock" => $clock,
            "ledger" => $ledger,
            "run" => $run,
            "policy" => $policy,
            "works" => $works,
            "rounds" => $rounds,
            "notes" => $notes,
            "transactions" => $transactions,
            "participant" => new PrivateNoteMigrationParticipant(
                $writer,
                $policy
            ),
        ];

        return $this->withRunServices($fixture);
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
            "note-migration-run-{$suffix}",
            "synthetic",
            "snapshot-{$suffix}",
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.32.0",
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
        $this->map(
            $fixture,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            "work",
            $targetId
        );
    }

    /** @param array<string,mixed> $fixture */
    private function mapRound(
        array $fixture,
        string $sourceId,
        string $targetId,
        UserId $userId,
        WorkId $workId
    ): void {
        $fixture["transactions"]->run(function () use (
            $fixture,
            $sourceId,
            $targetId,
            $userId,
            $workId
        ): void {
            $fixture["rounds"]->addForUser(
                $userId,
                ReadingRound::historical(
                    new ReadingRoundId($targetId),
                    $userId,
                    $workId,
                    ReadingPeriod::ended(null, ReadingDate::year(2000)),
                    $fixture["clock"]->now()
                )
            );
        });
        $this->map(
            $fixture,
            ReadingRoundMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            "reading_round",
            $targetId
        );
    }

    /** @param array<string,mixed> $fixture */
    private function map(
        array $fixture,
        string $sourceType,
        string $sourceId,
        string $targetType,
        string $targetId
    ): void {
        $record = new MigrationSourceRecord(
            $sourceType,
            $sourceId,
            ["approved_target_id" => $targetId]
        );
        $observation = $this->observe($fixture, $record);
        $fixture["commit"]->commit(
            $fixture["run"],
            $observation,
            static fn (): MigrationRecordOutcome => MigrationRecordOutcome::mapped([
                new MigrationTargetMapping(
                    $targetType,
                    $targetId,
                    MappingDisposition::Reused
                ),
            ])
        );
    }

    /** @param array<string,mixed> $fixture */
    private function note(
        array $fixture,
        string $sourceId,
        string $content = "<p>Unicode: café 📚</p>",
        ?string $roundSourceId = null
    ): MigrationSourceRecord {
        return MigrationSourceRecord::typed(
            PrivateNoteMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            new PrivateNotePlan(
                $fixture["user"],
                "work/source",
                $fixture["policy"]->sanitize($content),
                new DateTimeImmutable("2001-02-03T04:05:06.123456+00:00"),
                new DateTimeImmutable("2002-03-04T05:06:07.654321+00:00"),
                $roundSourceId
            )
        );
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function apply(
        array $fixture,
        MigrationSourceRecord $record
    ): array {
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
        PrivateNoteMigrationReason $reason
    ): void {
        try {
            $this->apply($fixture, $record);
            self::fail("Private Note migration failure was not raised.");
        } catch (PrivateNoteMigrationFailure $failure) {
            self::assertSame($reason, $failure->reason());
        }
    }

    /** @param array<string,mixed> $fixture */
    private function assertPlanningFailure(
        array $fixture,
        MigrationSourceRecord $record,
        PrivateNoteMigrationReason $reason
    ): void {
        try {
            $fixture["participant"]->plan($record, $fixture["target"]);
            self::fail("Private Note planning failure was not raised.");
        } catch (PrivateNoteMigrationFailure $failure) {
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

    /** @param array<string,mixed> $fixture */
    private function normalRead(array $fixture, UserId $userId): object
    {
        return (new GetMyPrivateNotesForWorkService(
            new PrivateNoteMigrationAuthenticatedUser($userId),
            $fixture["notes"],
            new RenderPrivateNoteContentService($fixture["policy"])
        ))->forWork(
            new WorkId("work-target"),
            new PrivateNotePageRequest(10)
        );
    }

    private function rows(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM `{$table}`");
    }

    /** @return array<string,int> */
    private function allCoreTableCounts(): array
    {
        $like = $this->database->esc_like($this->database->prefix . "biblio_") . "%";
        $tables = $this->database->get_col($this->database->prepare(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME",
            DB_NAME,
            $like
        ));
        $counts = [];
        foreach ($tables as $table) {
            $counts[(string) $table] = $this->rows((string) $table);
        }

        return $counts;
    }
}
