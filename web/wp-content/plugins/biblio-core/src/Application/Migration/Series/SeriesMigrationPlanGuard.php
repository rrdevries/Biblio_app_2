<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Series;

use Biblio\Core\Application\Migration\Runner\{
    DeterministicJson,
    MigrationSourceRecord,
    PlannedMigrationRecord,
    TypedMigrationPlan
};
use Biblio\Core\Application\Migration\SourceObservation;

final class SeriesMigrationPlanGuard
{
    /** @param class-string<TypedMigrationPlan> $expected */
    public static function require(
        string $sourceType,
        string $expected,
        MigrationSourceRecord $record,
        ?PlannedMigrationRecord $planned = null,
        ?SourceObservation $observation = null
    ): TypedMigrationPlan {
        $typed = $record->typedPlan();
        if ($record->sourceType() !== $sourceType || !$typed instanceof $expected) {
            throw new SeriesMigrationFailure(
                SeriesMigrationReason::InvalidTypedPlan,
                "Series source record has no matching typed V2 plan."
            );
        }
        if (
            DeterministicJson::hash($typed->canonicalPayload()) !== $record->payloadHash()
            || ($planned !== null && (
                !$planned->typedPlan() instanceof $expected
                || DeterministicJson::hash($planned->typedPlan()->canonicalPayload())
                    !== $record->payloadHash()
            ))
            || ($observation !== null && (
                $observation->sourceType() !== $sourceType
                || $observation->sourceId() !== $record->sourceId()
                || $observation->payloadHash() !== $record->payloadHash()
            ))
        ) {
            throw new SeriesMigrationFailure(
                SeriesMigrationReason::DivergentReplay,
                "Series plan, source record and observation diverge."
            );
        }
        return $typed;
    }

    private function __construct() {}
}
