<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Assessments;

use Biblio\Core\Application\Migration\Runner\{DeterministicJson,MigrationSourceRecord,PlannedMigrationRecord};
use Biblio\Core\Application\Migration\SourceObservation;

final class HistoricalRatingMigrationPlanGuard
{
    public static function require(
        MigrationSourceRecord $record,
        ?PlannedMigrationRecord $planned = null,
        ?SourceObservation $observation = null
    ): HistoricalRatingPlan {
        $typed = $record->typedPlan();
        if (
            $record->sourceType() !== HistoricalRatingMigrationParticipant::SOURCE_TYPE
            || !$typed instanceof HistoricalRatingPlan
        ) {
            throw new AssessmentMigrationFailure(
                AssessmentMigrationReason::InvalidTypedRatingPlan,
                "Historical Rating source record has no matching typed plan."
            );
        }
        if (
            DeterministicJson::hash($typed->canonicalPayload()) !== $record->payloadHash()
            || ($planned !== null && (
                !$planned->typedPlan() instanceof HistoricalRatingPlan
                || DeterministicJson::hash($planned->typedPlan()->canonicalPayload())
                    !== $record->payloadHash()
            ))
            || ($observation !== null && (
                $observation->sourceType() !== $record->sourceType()
                || $observation->sourceId() !== $record->sourceId()
                || $observation->payloadHash() !== $record->payloadHash()
            ))
        ) {
            throw new AssessmentMigrationFailure(
                AssessmentMigrationReason::DivergentReplay,
                "Historical Rating plan, record and observation diverge."
            );
        }
        return $typed;
    }

    private function __construct() {}
}
