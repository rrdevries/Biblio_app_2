<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reading;

use Biblio\Core\Application\Migration\Runner\{
    DeterministicJson,
    MigrationSourceRecord,
    PlannedMigrationRecord
};
use Biblio\Core\Application\Migration\SourceObservation;

final class ReadingTruthMigrationPlanGuard
{
    public static function require(
        MigrationSourceRecord $record,
        ?PlannedMigrationRecord $planned = null,
        ?SourceObservation $observation = null
    ): ReadingTruthPlan {
        $typed = $record->typedPlan();
        if (
            $record->sourceType() !== ReadingTruthMigrationParticipant::SOURCE_TYPE
            || !$typed instanceof ReadingTruthPlan
        ) {
            throw new ReadingTruthMigrationFailure(
                ReadingTruthMigrationReason::InvalidTypedPlan,
                "Reading Truth source record has no matching typed V2 plan."
            );
        }
        if (
            DeterministicJson::hash($typed->canonicalPayload())
                !== $record->payloadHash()
            || ($planned !== null && (
                !$planned->typedPlan() instanceof ReadingTruthPlan
                || DeterministicJson::hash(
                    $planned->typedPlan()->canonicalPayload()
                ) !== $record->payloadHash()
            ))
            || ($observation !== null && (
                $observation->sourceType()
                    !== ReadingTruthMigrationParticipant::SOURCE_TYPE
                || $observation->sourceId() !== $record->sourceId()
                || $observation->payloadHash() !== $record->payloadHash()
            ))
        ) {
            throw new ReadingTruthMigrationFailure(
                ReadingTruthMigrationReason::DivergentReplay,
                "Reading Truth plan, source record and observation diverge."
            );
        }

        return $typed;
    }

    private function __construct()
    {
    }
}
