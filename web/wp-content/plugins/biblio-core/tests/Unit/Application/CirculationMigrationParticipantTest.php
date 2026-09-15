<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Identity\{
    PersonalMigrationTarget,
    PersonalMigrationTargetReadiness
};
use Biblio\Core\Application\Migration\Circulation\{
    CirculationMigrationFailure,
    CirculationMigrationParticipant,
    CirculationMigrationReason,
    CirculationPlan
};
use Biblio\Core\Application\Migration\{
    MigrationDisposition,
    MigrationMode,
    MigrationRun,
    QuarantineReason,
    SourceObservation
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationPlanningTarget,
    MigrationSourceRecord
};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\{LibraryId,LibraryName};
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CirculationMigrationParticipantTest extends TestCase
{
    public function testCoherentOpenBorrowedIsPreservedAndRemainsOpenInEvidence(): void
    {
        $private = "Synthetic Private Counterparty";
        $record = $this->record(
            "loan-borrowed",
            "borrowed",
            null,
            $private,
            includeBook: true
        );
        $participant = new CirculationMigrationParticipant();
        $plan = $participant->plan($record, $this->target());

        self::assertSame(MigrationDisposition::PreservedDeferred, $plan->disposition());
        self::assertSame(
            CirculationMigrationReason::ProductTargetDeferred->value,
            $plan->reasonCode()
        );
        self::assertSame([], $plan->operations());

        $outcome = $participant->apply(
            $record,
            $this->observation($record),
            $plan,
            $this->target()
        );
        self::assertSame(MigrationDisposition::PreservedDeferred, $outcome->disposition());
        self::assertSame([], $outcome->mappings());
        $evidence = json_decode((string) $outcome->evidenceJson(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame("open", $evidence["classification"]["source_state"]);
        self::assertFalse($evidence["classification"]["material_lifecycle_conflict"]);
        self::assertSame("day", $evidence["source_payload"]["book_occurrences"][0]
            ["record"]["startDate"]["precision"]);
        self::assertSame($private, $evidence["source_payload"]["copy_occurrences"][0]
            ["record"]["counterparty"]);
    }

    public function testCoherentOpenLentOutPreservesCopyAndMissingCounterparty(): void
    {
        $record = $this->record(
            "loan-lent",
            "lent_out",
            null,
            null,
            includeBook: false
        );
        $participant = new CirculationMigrationParticipant();
        $plan = $participant->plan($record, $this->target());
        $outcome = $participant->apply(
            $record,
            $this->observation($record),
            $plan,
            $this->target()
        );
        $evidence = json_decode((string) $outcome->evidenceJson(), true, 32, JSON_THROW_ON_ERROR);

        self::assertSame(MigrationDisposition::PreservedDeferred, $outcome->disposition());
        self::assertSame(["lent_out"], $evidence["classification"]["source_types"]);
        self::assertSame([], $evidence["source_payload"]["book_occurrences"]);
        self::assertSame(
            "copy-loan-lent",
            $evidence["source_payload"]["copy_occurrences"][0]["copy_id"]
        );
        self::assertNull($evidence["source_payload"]["copy_occurrences"][0]
            ["record"]["counterparty"]);
        self::assertSame([], $outcome->mappings());
    }

    public function testBookOpenCopyClosedConflictIsQuarantinedWithoutPrecedence(): void
    {
        $private = "Synthetic Conflict Counterparty";
        $record = $this->record(
            "loan-conflict",
            "borrowed",
            ["value" => "2024-03-05", "precision" => "day"],
            $private,
            includeBook: true
        );
        $participant = new CirculationMigrationParticipant();
        $plan = $participant->plan($record, $this->target());

        self::assertSame(MigrationDisposition::Quarantined, $plan->disposition());
        self::assertSame(
            QuarantineReason::AmbiguousCirculationSemantics->value,
            $plan->reasonCode()
        );
        self::assertSame([], $plan->operations());
        self::assertSame(
            "Circulation source representations conflict; no source precedence was applied.",
            $plan->safeExplanation()
        );
        self::assertStringNotContainsString($private, (string) $plan->safeExplanation());

        $outcome = $participant->apply(
            $record,
            $this->observation($record),
            $plan,
            $this->target()
        );
        $evidence = json_decode((string) $outcome->evidenceJson(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(MigrationDisposition::Quarantined, $outcome->disposition());
        self::assertSame(["closed", "open"], $evidence["classification"]["source_states"]);
        self::assertSame("conflicting", $evidence["classification"]["source_state"]);
        self::assertTrue($evidence["classification"]["material_lifecycle_conflict"]);
        self::assertCount(1, $evidence["source_payload"]["book_occurrences"]);
        self::assertCount(1, $evidence["source_payload"]["copy_occurrences"]);
        self::assertNull($evidence["source_payload"]["book_occurrences"][0]
            ["record"]["endDate"]);
        self::assertSame(
            "2024-03-05",
            $evidence["source_payload"]["copy_occurrences"][0]
                ["record"]["endDate"]["value"]
        );
        self::assertSame([], $outcome->mappings());
    }

    public function testInvalidTypedBoundaryDoesNotExposePrivateSourceValues(): void
    {
        $private = "Synthetic Invalid Counterparty Sentinel";
        $record = new MigrationSourceRecord(
            CirculationMigrationParticipant::SOURCE_TYPE,
            "loan-invalid",
            ["counterparty" => $private]
        );

        try {
            (new CirculationMigrationParticipant())->plan($record, $this->target());
            self::fail("An untyped circulation record must fail closed.");
        } catch (CirculationMigrationFailure $failure) {
            self::assertSame(
                CirculationMigrationReason::InvalidTypedPlan->value,
                $failure->reasonCode()
            );
            self::assertStringNotContainsString($private, $failure->getMessage());
            self::assertStringNotContainsString($private, $failure->reasonCode());
        }
    }

    /** @param array{value:string,precision:string}|null $copyEnd */
    private function record(
        string $id,
        string $type,
        ?array $copyEnd,
        ?string $counterparty,
        bool $includeBook
    ): MigrationSourceRecord {
        $sourceRecord = [
            "id" => $id,
            "type" => $type,
            "counterparty" => $counterparty,
            "startDate" => ["value" => "2024-02-03", "precision" => "day"],
            "endDate" => null,
            "notes" => "Synthetic restricted note",
        ];
        $books = $includeBook ? [[
            "book_id" => "book-{$id}",
            "record" => $sourceRecord,
        ]] : [];
        $sourceRecord["endDate"] = $copyEnd;
        $copies = [[
            "copy_id" => "copy-{$id}",
            "book_id" => "book-{$id}",
            "record" => $sourceRecord,
        ]];
        $typed = new CirculationPlan($id, $books, $copies);

        return MigrationSourceRecord::typed(
            CirculationMigrationParticipant::SOURCE_TYPE,
            $id,
            $typed,
            ["v1.book:book-{$id}", "v1.copy:copy-{$id}"]
        );
    }

    private function target(): MigrationPlanningTarget
    {
        return new MigrationPlanningTarget(new PersonalMigrationTarget(
            new UserId("123"),
            new LibraryId("library-circulation-test"),
            LibraryName::personalDefault(),
            new PersonalMigrationTargetReadiness([])
        ));
    }

    private function observation(MigrationSourceRecord $record): SourceObservation
    {
        $at = new DateTimeImmutable("2026-09-15T12:00:00.123456+00:00");
        $run = MigrationRun::start(
            "circulation-test-run",
            "biblio-v1",
            "synthetic-circulation-snapshot",
            str_repeat("a", 64),
            "books-29.authors-2.reading-goals-2",
            "mig-02-circ-1",
            new UserId("123"),
            new LibraryId("library-circulation-test"),
            MigrationMode::Apply,
            $at
        );

        return SourceObservation::observe(
            $run,
            $record->sourceType(),
            $record->sourceId(),
            $record->payloadHash(),
            $record->payload(),
            null,
            $at
        );
    }
}
