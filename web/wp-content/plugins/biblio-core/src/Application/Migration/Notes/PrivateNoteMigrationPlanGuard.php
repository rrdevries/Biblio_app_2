<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Notes;

use Biblio\Core\Application\Migration\Runner\{
    DeterministicJson,
    MigrationSourceRecord,
    PlannedMigrationRecord
};
use Biblio\Core\Application\Migration\SourceObservation;

final class PrivateNoteMigrationPlanGuard
{
    public static function require(
        MigrationSourceRecord $record,
        ?PlannedMigrationRecord $planned = null,
        ?SourceObservation $observation = null
    ): PrivateNotePlan {
        $typed = $record->typedPlan();
        if (
            $record->sourceType() !== PrivateNoteMigrationParticipant::SOURCE_TYPE
            || !$typed instanceof PrivateNotePlan
        ) {
            throw new PrivateNoteMigrationFailure(
                PrivateNoteMigrationReason::InvalidTypedPlan,
                "Private Note source record has no matching typed V2 plan."
            );
        }
        if (
            DeterministicJson::hash($typed->canonicalPayload())
                !== $record->payloadHash()
            || ($planned !== null && (
                !$planned->typedPlan() instanceof PrivateNotePlan
                || DeterministicJson::hash(
                    $planned->typedPlan()->canonicalPayload()
                ) !== $record->payloadHash()
            ))
            || ($observation !== null && (
                $observation->sourceType()
                    !== PrivateNoteMigrationParticipant::SOURCE_TYPE
                || $observation->sourceId() !== $record->sourceId()
                || $observation->payloadHash() !== $record->payloadHash()
            ))
        ) {
            throw new PrivateNoteMigrationFailure(
                PrivateNoteMigrationReason::DivergentReplay,
                "Private Note plan, source record and observation diverge."
            );
        }

        return $typed;
    }

    private function __construct()
    {
    }
}
