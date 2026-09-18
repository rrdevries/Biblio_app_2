<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Catalog;

use Biblio\Core\Application\Migration\Runner\{
    DeterministicJson,
    MigrationSourceRecord,
    PlannedMigrationRecord
};
use Biblio\Core\Application\Migration\SourceObservation;

final class CatalogWorkContainmentPlanGuard
{
    public static function require(
        MigrationSourceRecord $record,
        ?PlannedMigrationRecord $planned = null,
        ?SourceObservation $observation = null
    ): CatalogWorkContainmentPlan {
        $typed = $record->typedPlan();
        if (
            $record->sourceType()
                !== CatalogWorkContainmentMigrationParticipant::SOURCE_TYPE
            || !$typed instanceof CatalogWorkContainmentPlan
        ) {
            throw new CatalogWorkContainmentMigrationFailure(
                CatalogWorkContainmentMigrationReason::InvalidTypedPlan,
                "Containment source record has no matching typed V2 plan."
            );
        }
        if (
            DeterministicJson::hash($typed->canonicalPayload())
                !== $record->payloadHash()
            || ($planned !== null && (
                !$planned->typedPlan() instanceof CatalogWorkContainmentPlan
                || DeterministicJson::hash(
                    $planned->typedPlan()->canonicalPayload()
                ) !== $record->payloadHash()
            ))
            || ($observation !== null && (
                $observation->sourceType() !== $record->sourceType()
                || $observation->sourceId() !== $record->sourceId()
                || $observation->payloadHash() !== $record->payloadHash()
            ))
        ) {
            throw new CatalogWorkContainmentMigrationFailure(
                CatalogWorkContainmentMigrationReason::DivergentReplay,
                "Containment plan, source record and observation diverge."
            );
        }
        return $typed;
    }

    private function __construct()
    {
    }
}
