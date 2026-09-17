<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Preservation;

use Biblio\Core\Application\Migration\Runner\{
    MigrationSourceInspection,
    MigrationPlanningTarget,
    MigrationSourceRecord,
    PlannedMigrationRecord
};
use Biblio\Core\Application\Migration\SourceObservation;

final readonly class PreservedSourceEvidencePlanGuard
{
    public function __construct(private PreservedSourceEvidenceAdmissionRegistry $admissions)
    {
    }

    public function require(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target,
        ?PlannedMigrationRecord $planned = null,
        ?SourceObservation $observation = null
    ): PreservedSourceEvidencePlan {
        unset($target);
        $typed = $record->typedPlan();
        if (
            !$typed instanceof PreservedSourceEvidencePlan
            || $record->sourceType() !== PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE
            || $typed->sourceIdentity() !== $record->sourceId()
            || $typed->canonicalPayload() !== $record->payload()
            || ($planned !== null && (
                $planned->typedPlan() !== $typed
                || $planned->disposition()->value !== "preserved_deferred"
                || $planned->reasonCode() !== $typed->reasonCode()
                || $planned->operations() !== []
                || $planned->dependencies() !== []
            ))
            || ($observation !== null && (
                $observation->sourceType() !== $record->sourceType()
                || $observation->sourceId() !== $record->sourceId()
                || $observation->sourceFamily() !== $typed->sourceFamily()
                || $observation->sourceSnapshot() !== $typed->manifestSha256()
                || !hash_equals($observation->payloadHash(), $record->payloadHash())
            ))
        ) {
            throw new PreservedSourceEvidenceMigrationFailure(
                "invalid_preserved_source_evidence_plan",
                "Preserved source evidence requires one matching reviewed typed plan."
            );
        }
        $this->admissions->assertAdmitted($typed);
        return $typed;
    }

    /** @param list<string> $mappingContracts */
    public function assertPreparedProvenance(
        PreservedSourceEvidencePlan $plan,
        MigrationSourceInspection $inspection,
        array $mappingContracts
    ): void {
        if (
            $plan->adapterId() !== $inspection->adapter()->adapterId()
            || $plan->sourceFamily() !== $inspection->adapter()->sourceFamily()
            || $plan->sourceVersion() !== $inspection->profile()->sourceVersion()
            || !hash_equals(
                $plan->manifestSha256(),
                $inspection->package()->manifestDigest()
            )
            || !in_array($plan->mappingContract(), $mappingContracts, true)
        ) {
            throw new PreservedSourceEvidenceMigrationFailure(
                "invalid_preserved_source_evidence_provenance",
                "Preserved source evidence does not match prepared source provenance."
            );
        }
    }
}
