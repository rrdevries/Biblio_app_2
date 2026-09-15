<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Circulation;

use Biblio\Core\Application\Migration\{
    MigrationDisposition,
    MigrationRecordOutcome,
    QuarantineReason,
    SourceObservation
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationParticipant,
    MigrationPlanningTarget,
    MigrationSourceRecord,
    PlannedMigrationRecord
};

final readonly class CirculationMigrationParticipant implements MigrationParticipant
{
    public const SOURCE_TYPE = "v1.circulation_round";

    public function sourceType(): string { return self::SOURCE_TYPE; }

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord {
        unset($target);
        $typed = CirculationMigrationPlanGuard::require($record);
        if ($typed->hasMaterialLifecycleConflict()) {
            return new PlannedMigrationRecord(
                MigrationDisposition::Quarantined,
                reasonCode: QuarantineReason::AmbiguousCirculationSemantics->value,
                safeExplanation: self::CONFLICT_EXPLANATION,
                typedPlan: $typed
            );
        }

        return new PlannedMigrationRecord(
            MigrationDisposition::PreservedDeferred,
            reasonCode: CirculationMigrationReason::ProductTargetDeferred->value,
            typedPlan: $typed
        );
    }

    public function apply(
        MigrationSourceRecord $record,
        SourceObservation $observation,
        PlannedMigrationRecord $plan,
        MigrationPlanningTarget $target
    ): MigrationRecordOutcome {
        unset($target);
        $typed = CirculationMigrationPlanGuard::require(
            $record,
            $plan,
            $observation
        );
        $evidence = $typed->restrictedEvidence(
            $observation->runId(),
            $observation->id(),
            $observation->sourceFamily(),
            $observation->sourceType(),
            $observation->sourceSnapshot(),
            $observation->payloadHash()
        );

        if ($typed->hasMaterialLifecycleConflict()) {
            return MigrationRecordOutcome::quarantined(
                QuarantineReason::AmbiguousCirculationSemantics,
                self::CONFLICT_EXPLANATION,
                $evidence
            );
        }

        return MigrationRecordOutcome::preserved(
            CirculationMigrationReason::ProductTargetDeferred->value,
            $evidence
        );
    }

    private const CONFLICT_EXPLANATION =
        "Circulation source representations conflict; no source precedence was applied.";
}
