<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Preservation;

use Biblio\Core\Application\Migration\{
    MigrationDisposition,
    MigrationEvidence,
    MigrationLedgerRepository,
    MigrationRecordOutcome,
    PriorPreservedEvidence,
    SourceObservation
};
use Biblio\Core\Application\Migration\Runner\{
    CrossRunMigrationReplayParticipant,
    PreparedMigrationPreflightParticipant,
    DeterministicJson,
    MigrationParticipant,
    PreparedMigrationContextParticipant,
    MigrationSourceInspection,
    MigrationPlanningTarget,
    MigrationSourceRecord,
    PlannedMigrationRecord
};

final readonly class PreservedSourceEvidenceMigrationParticipant implements
    MigrationParticipant,
    CrossRunMigrationReplayParticipant,
    PreparedMigrationPreflightParticipant,
    PreparedMigrationContextParticipant
{
    public const SOURCE_TYPE = "preserved_source_evidence";

    public function __construct(
        private MigrationLedgerRepository $ledger,
        private PreservedSourceEvidencePlanGuard $guard
    ) {
    }

    public function sourceType(): string { return self::SOURCE_TYPE; }

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord {
        $typed = $this->guard->require($record, $target);
        return new PlannedMigrationRecord(
            MigrationDisposition::PreservedDeferred,
            reasonCode: $typed->reasonCode(),
            typedPlan: $typed
        );
    }

    public function apply(
        MigrationSourceRecord $record,
        SourceObservation $observation,
        PlannedMigrationRecord $plan,
        MigrationPlanningTarget $target
    ): MigrationRecordOutcome {
        $typed = $this->guard->require($record, $target, $plan, $observation);
        return MigrationRecordOutcome::preserved(
            $typed->reasonCode(),
            $typed->evidenceDescriptor(),
            $typed->locator()
        );
    }

    public function assertPreparedContext(
        MigrationSourceRecord $record,
        PlannedMigrationRecord $plan,
        MigrationSourceInspection $inspection,
        array $mappingContracts
    ): void {
        $typed = $record->typedPlan();
        if (
            !$typed instanceof PreservedSourceEvidencePlan
            || $plan->typedPlan() !== $typed
        ) {
            throw new PreservedSourceEvidenceMigrationFailure(
                "invalid_preserved_source_evidence_plan",
                "Preserved source evidence requires one matching reviewed typed plan."
            );
        }
        $this->guard->assertPreparedProvenance(
            $typed,
            $inspection,
            $mappingContracts
        );
    }

    public function hasEquivalentPrior(
        MigrationSourceRecord $record,
        PlannedMigrationRecord $plan,
        MigrationPlanningTarget $target
    ): bool {
        $this->assertPreparedPreflight($record, $plan, $target);
        $typed = $this->guard->require($record, $target, $plan);
        $matches = $this->ledger->priorPreservations(
            $target->userId(),
            $target->libraryId(),
            $typed->sourceFamily(),
            self::SOURCE_TYPE,
            $typed->sourceIdentity()
        );
        if ($matches === []) {
            return false;
        }

        $expectedPayload = DeterministicJson::encode($typed->canonicalPayload());
        $expectedEvidence = MigrationEvidence::canonicalJson(
            $typed->evidenceDescriptor()
        );
        foreach ($matches as $match) {
            if (!$this->equivalent(
                $match,
                $record,
                $typed,
                $expectedPayload,
                $expectedEvidence
            )) {
                throw new PreservedSourceEvidenceMigrationFailure(
                    "divergent_preserved_source_evidence",
                    "Prior preserved source evidence diverges from the reviewed plan."
                );
            }
        }
        return true;
    }

    public function assertPreparedPreflight(
        MigrationSourceRecord $record,
        PlannedMigrationRecord $plan,
        MigrationPlanningTarget $target
    ): void {
        $typed = $this->guard->require($record, $target, $plan);
        foreach ($typed->forbiddenSourceIdentities() as $forbidden) {
            if ($this->ledger->committedSourceTargets(
                $target->userId(),
                $target->libraryId(),
                $typed->sourceFamily(),
                $forbidden["source_type"],
                $forbidden["source_id"]
            ) !== []) {
                throw new PreservedSourceEvidenceMigrationFailure(
                    "prior_mapping_conflicts_with_terminal_preservation",
                    "A committed migration mapping conflicts with terminal source preservation."
                );
            }
        }
    }

    private function equivalent(
        PriorPreservedEvidence $prior,
        MigrationSourceRecord $record,
        PreservedSourceEvidencePlan $typed,
        string $expectedPayload,
        string $expectedEvidence
    ): bool {
        return hash_equals($record->payloadHash(), $prior->payloadHash())
            && hash_equals($typed->manifestSha256(), $prior->sourceSnapshot())
            && $typed->sourceVersion() === $prior->sourceVersion()
            && hash_equals($expectedPayload, $prior->payloadJson())
            && $typed->reasonCode() === $prior->reasonCode()
            && $typed->reasonCode() === $prior->preservationReason()
            && hash_equals($expectedEvidence, $prior->evidenceJson())
            && $typed->locator() === $prior->evidenceReference();
    }
}
