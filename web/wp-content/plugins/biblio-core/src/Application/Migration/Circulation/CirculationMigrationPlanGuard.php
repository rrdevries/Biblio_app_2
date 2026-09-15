<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Circulation;

use Biblio\Core\Application\Migration\Runner\{
    MigrationSourceRecord,
    PlannedMigrationRecord
};
use Biblio\Core\Application\Migration\SourceObservation;

final class CirculationMigrationPlanGuard
{
    public static function require(
        MigrationSourceRecord $record,
        ?PlannedMigrationRecord $planned = null,
        ?SourceObservation $observation = null
    ): CirculationPlan {
        $typed = $record->typedPlan();
        if (!$typed instanceof CirculationPlan) {
            throw self::invalid();
        }
        if (
            $typed->sourceId() !== $record->sourceId()
            || $typed->canonicalPayload() !== $record->payload()
            || ($planned !== null && $planned->typedPlan() !== $typed)
            || ($observation !== null && (
                $observation->sourceType() !== $record->sourceType()
                || $observation->sourceId() !== $record->sourceId()
                || !hash_equals($observation->payloadHash(), $record->payloadHash())
            ))
        ) {
            throw self::invalid();
        }
        return $typed;
    }

    private static function invalid(): CirculationMigrationFailure
    {
        return new CirculationMigrationFailure(
            CirculationMigrationReason::InvalidTypedPlan,
            "Circulation migration requires matching reviewed evidence."
        );
    }
}
