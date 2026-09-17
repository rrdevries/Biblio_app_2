<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Identity\{PersonalMigrationTarget,PersonalMigrationTargetReadiness};
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
use Biblio\Core\Application\Migration\Catalog\CatalogMigrationItemRepository;
use Biblio\Core\Application\Migration\Reconciliation\CoreMigrationTargetInspector;
use Biblio\Core\Application\Migration\Runner\{MigrationParticipant,MigrationPlanningTarget,MigrationSourceRecord};
use Biblio\Core\Application\Migration\Series\{
    CatalogSeriesMigrationParticipant,
    CatalogSeriesPlan,
    CatalogWorkSeriesMigrationParticipant,
    CatalogWorkSeriesPlan,
    SeriesMigrationFailure,
    SeriesMigrationReason,
    SeriesMigrationWriter
};
use Biblio\Core\Application\Metadata\Author\AuthorContributorCreditRepository;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextRepository;
use Biblio\Core\Catalog\{AuthorRepository,EditionIdentifierClaimRepository,EditionRepository,ItemLocalDetailsRepository,Series,SeriesId,SeriesPosition,Work,WorkId};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{WpdbLibraryRepository,WpdbMigrationLedgerRepository,WpdbSeriesRepository,WpdbTransactionManager,WpdbWorkRepository};
use Biblio\Core\Library\{Library,LibraryId,LibraryName};
use Biblio\Core\Notes\PrivateNoteRepository;
use Biblio\Core\Reading\ReadingRoundRepository;
use DateTimeImmutable;

final readonly class SeriesMigrationTestClock implements MigrationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-17T10:00:00.123456+00:00");
    }
}

final class SeriesMigrationParticipantTest extends PersistenceIntegrationTestCase
{
    public function testSeriesUsesDeterministicIdentityWithoutNameReuseAndReplaysExactly(): void
    {
        $fixture = $this->fixture("series-replay");
        $fixture["series"]->save(new Series(new SeriesId("unrelated-series"), "Exact Name"));
        $record = $this->seriesRecord("series/source", "Exact Name");

        $first = $this->apply($fixture, $fixture["series_participant"], $record);
        $targetId = $first["outcome"]->mappings()[0]->targetId();
        self::assertSame(
            SeriesMigrationWriter::targetSeriesId("series/source")->value(),
            $targetId
        );
        self::assertNotSame("unrelated-series", $targetId);
        self::assertSame(MappingDisposition::Created, $first["outcome"]->mappings()[0]->disposition());
        self::assertTrue($this->targetExists($fixture, $record, $first));

        $later = $this->laterRun($fixture, "series-replay-later");
        $replay = $this->apply($later, $later["series_participant"], $record);
        self::assertSame($targetId, $replay["outcome"]->mappings()[0]->targetId());
        self::assertSame(MappingDisposition::Reused, $replay["outcome"]->mappings()[0]->disposition());

        $changed = $this->laterRun($later, "series-replay-changed");
        try {
            $this->apply(
                $changed,
                $changed["series_participant"],
                $this->seriesRecord("series/source", "Changed Name")
            );
            self::fail("Changed Series bytes were silently replayed.");
        } catch (SeriesMigrationFailure $failure) {
            self::assertSame(SeriesMigrationReason::DivergentReplay, $failure->reason());
        }
        self::assertSame("Exact Name", $fixture["series"]->find(new SeriesId($targetId))?->displayName());
    }

    public function testMembershipUsesExactDependenciesConvergesAndRejectsPositionConflict(): void
    {
        $fixture = $this->fixture("membership");
        $this->mapWork($fixture, "work/source", "work-target");
        $this->apply(
            $fixture,
            $fixture["series_participant"],
            $this->seriesRecord("series/source", "Membership Series")
        );

        $firstRecord = $this->membershipRecord("membership/representative", "2");
        $first = $this->apply(
            $fixture,
            $fixture["membership_participant"],
            $firstRecord
        );
        $second = $this->apply(
            $fixture,
            $fixture["membership_participant"],
            $this->membershipRecord("membership/alias", "2")
        );
        self::assertSame(MappingDisposition::Created, $first["outcome"]->mappings()[0]->disposition());
        self::assertSame(MappingDisposition::Reused, $second["outcome"]->mappings()[0]->disposition());
        self::assertSame(
            $first["outcome"]->mappings()[0]->targetId(),
            $second["outcome"]->mappings()[0]->targetId()
        );
        self::assertCount(1, $fixture["series"]->membershipsForWorks([new WorkId("work-target")])["work-target"]);
        self::assertTrue($this->targetExists($fixture, $firstRecord, $first));

        try {
            $this->apply(
                $fixture,
                $fixture["membership_participant"],
                $this->membershipRecord("membership/conflict", "3")
            );
            self::fail("Incompatible positive Series position was silently reused.");
        } catch (SeriesMigrationFailure $failure) {
            self::assertSame(SeriesMigrationReason::MembershipConflict, $failure->reason());
        }
        self::assertSame(
            "2",
            $fixture["series"]->membershipsForWorks([new WorkId("work-target")])["work-target"][0]->position()->value()
        );

        $later = $this->laterRun($fixture, "membership-replay");
        $replay = $this->apply(
            $later,
            $later["membership_participant"],
            $firstRecord
        );
        self::assertSame(MappingDisposition::Reused, $replay["outcome"]->mappings()[0]->disposition());

        $changed = $this->laterRun($later, "membership-changed");
        try {
            $this->apply(
                $changed,
                $changed["membership_participant"],
                $this->membershipRecord("membership/representative", "3")
            );
            self::fail("Changed membership payload was silently replayed.");
        } catch (SeriesMigrationFailure $failure) {
            self::assertSame(SeriesMigrationReason::DivergentReplay, $failure->reason());
        }
    }

    public function testUnknownPositionRemainsNullAndMissingDependenciesFailClosed(): void
    {
        $fixture = $this->fixture("unknown");
        $this->mapWork($fixture, "work/source", "work-target");
        $this->apply(
            $fixture,
            $fixture["series_participant"],
            $this->seriesRecord("series/source", "Unknown Position")
        );
        $this->apply(
            $fixture,
            $fixture["membership_participant"],
            $this->membershipRecord("membership/unknown", null)
        );
        self::assertNull(
            $fixture["series"]->membershipsForWorks([new WorkId("work-target")])["work-target"][0]->position()->value()
        );

        $missing = $this->fixture("missing");
        try {
            $this->apply(
                $missing,
                $missing["membership_participant"],
                $this->membershipRecord("membership/missing", "1")
            );
            self::fail("Missing Work/Series dependencies were accepted.");
        } catch (SeriesMigrationFailure $failure) {
            self::assertSame(SeriesMigrationReason::MissingTargetReference, $failure->reason());
        }
    }

    public function testOccupiedDeterministicTargetWithoutMappingFailsClosed(): void
    {
        $fixture = $this->fixture("collision");
        $target = SeriesMigrationWriter::targetSeriesId("series/collision");
        $fixture["series"]->save(new Series($target, "Unrelated Existing Series"));

        try {
            $this->apply(
                $fixture,
                $fixture["series_participant"],
                $this->seriesRecord("series/collision", "Source Series")
            );
            self::fail("Occupied deterministic Series target was reused.");
        } catch (SeriesMigrationFailure $failure) {
            self::assertSame(SeriesMigrationReason::TargetCollision, $failure->reason());
        }
        self::assertSame("Unrelated Existing Series", $fixture["series"]->find($target)?->displayName());
    }

    /** @return array<string,mixed> */
    private function fixture(string $suffix): array
    {
        $library = new LibraryId("series-migration-library-{$suffix}");
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(
            Library::privateLibrary($library, new LibraryName("Library {$suffix}"))
        );
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $clock = new SeriesMigrationTestClock();
        $run = $ledger->beginOrResume(MigrationRun::start(
            "series-migration-run-{$suffix}",
            "synthetic",
            "snapshot-{$suffix}",
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.45.0",
            new UserId("series-migration-user-{$suffix}"),
            $library,
            MigrationMode::Apply,
            $clock->now()
        ));
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $series = new WpdbSeriesRepository($this->database, $this->tableNames);
        $writer = new SeriesMigrationWriter($ledger, $series, $works);
        return $this->withRunServices([
            "library" => $library,
            "ledger" => $ledger,
            "transactions" => new WpdbTransactionManager($this->database),
            "clock" => $clock,
            "run" => $run,
            "works" => $works,
            "series" => $series,
            "series_participant" => new CatalogSeriesMigrationParticipant($writer),
            "membership_participant" => new CatalogWorkSeriesMigrationParticipant($writer),
        ]);
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function withRunServices(array $fixture): array
    {
        $fixture["target"] = new MigrationPlanningTarget(new PersonalMigrationTarget(
            $fixture["run"]->targetUserId(),
            $fixture["library"],
            new LibraryName("Migration target"),
            new PersonalMigrationTargetReadiness([])
        ));
        $fixture["observe"] = new ObserveSourceRecordService($fixture["ledger"], $fixture["clock"]);
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
            "series-migration-run-{$suffix}",
            "synthetic",
            "snapshot-{$suffix}",
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.45.0",
            $fixture["run"]->targetUserId(),
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
            ["approved_work_id" => $targetId]
        );
        $observation = $this->observe($fixture, $record);
        $fixture["commit"]->commit(
            $fixture["run"],
            $observation,
            static fn (): MigrationRecordOutcome => MigrationRecordOutcome::mapped([
                new MigrationTargetMapping("work", $targetId, MappingDisposition::Reused),
            ])
        );
    }

    private function seriesRecord(string $sourceId, string $name): MigrationSourceRecord
    {
        return MigrationSourceRecord::typed(
            CatalogSeriesMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            new CatalogSeriesPlan($name, "series-test-contract")
        );
    }

    private function membershipRecord(string $sourceId, ?string $position): MigrationSourceRecord
    {
        return MigrationSourceRecord::typed(
            CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            new CatalogWorkSeriesPlan(
                "work/source",
                "series/source",
                $position === null ? SeriesPosition::unknown() : SeriesPosition::known($position),
                "series-test-contract"
            )
        );
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function apply(array $fixture, MigrationParticipant $participant, MigrationSourceRecord $record): array
    {
        $plan = $participant->plan($record, $fixture["target"]);
        $observation = $this->observe($fixture, $record);
        $outcome = $fixture["commit"]->commit(
            $fixture["run"],
            $observation,
            fn (): MigrationRecordOutcome => $participant->apply(
                $record,
                $observation,
                $plan,
                $fixture["target"]
            )
        );
        return compact("plan", "observation", "outcome");
    }

    /** @param array<string,mixed> $fixture */
    private function observe(array $fixture, MigrationSourceRecord $record): SourceObservation
    {
        return $fixture["observe"]->observe(
            $fixture["run"],
            $record->sourceType(),
            $record->sourceId(),
            $record->payloadHash(),
            $record->payload()
        );
    }

    /** @param array<string,mixed> $fixture @param array<string,mixed> $applied */
    private function targetExists(array $fixture, MigrationSourceRecord $record, array $applied): bool
    {
        $snapshot = $fixture["ledger"]->snapshot($fixture["run"]->id());
        $stored = null;
        foreach ($snapshot->observations() as $observation) {
            if (
                $observation->sourceType() === $record->sourceType()
                && $observation->sourceId() === $record->sourceId()
            ) {
                $stored = $observation;
                break;
            }
        }
        self::assertNotNull($stored);
        $inspector = new CoreMigrationTargetInspector(
            $fixture["works"],
            $this->createStub(EditionRepository::class),
            $this->createStub(CatalogMigrationItemRepository::class),
            $this->createStub(EditionIdentifierClaimRepository::class),
            $this->createStub(LibraryCatalogContextRepository::class),
            $this->createStub(ItemLocalDetailsRepository::class),
            $this->createStub(AuthorRepository::class),
            $this->createStub(AuthorContributorCreditRepository::class),
            $this->createStub(ReadingRoundRepository::class),
            $this->createStub(PrivateNoteRepository::class),
            series: $fixture["series"]
        );
        return $inspector->exists(
            $fixture["run"],
            $record,
            $stored,
            $applied["outcome"]->mappings()[0],
            $snapshot
        );
    }
}
