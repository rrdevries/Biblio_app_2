<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reading;

use Biblio\Core\Application\Migration\Runner\{
    DeterministicJson,
    MigrationSourceRecord,
    PlannedMigrationRecord
};
use Biblio\Core\Application\Migration\SourceObservation;

final class ReadingRoundMigrationPlanGuard
{
    public static function require(
        MigrationSourceRecord $record,
        ?PlannedMigrationRecord $planned = null,
        ?SourceObservation $observation = null
    ): ReadingRoundPlan {
        $typed = $record->typedPlan();
        if (
            $record->sourceType() !== ReadingRoundMigrationParticipant::SOURCE_TYPE
            || !$typed instanceof ReadingRoundPlan
        ) {
            throw new ReadingRoundMigrationFailure(
                ReadingRoundMigrationReason::InvalidTypedPlan,
                "Reading Round source record has no matching typed V2 plan."
            );
        }
        if (
            DeterministicJson::hash($typed->canonicalPayload())
                !== $record->payloadHash()
            || ($planned !== null && (
                !$planned->typedPlan() instanceof ReadingRoundPlan
                || DeterministicJson::hash(
                    $planned->typedPlan()->canonicalPayload()
                ) !== $record->payloadHash()
            ))
            || ($observation !== null && (
                $observation->sourceType()
                    !== ReadingRoundMigrationParticipant::SOURCE_TYPE
                || $observation->sourceId() !== $record->sourceId()
                || $observation->payloadHash() !== $record->payloadHash()
            ))
        ) {
            throw new ReadingRoundMigrationFailure(
                ReadingRoundMigrationReason::DivergentReplay,
                "Reading Round plan, source record and observation diverge."
            );
        }

        return $typed;
    }

    private function __construct()
    {
    }
}
