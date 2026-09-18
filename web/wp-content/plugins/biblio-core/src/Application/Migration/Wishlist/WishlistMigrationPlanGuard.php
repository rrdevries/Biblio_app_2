<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Wishlist;

use Biblio\Core\Application\Migration\Runner\{
    DeterministicJson,
    MigrationSourceRecord,
    PlannedMigrationRecord
};
use Biblio\Core\Application\Migration\SourceObservation;

final class WishlistMigrationPlanGuard
{
    public static function require(
        MigrationSourceRecord $record,
        ?PlannedMigrationRecord $planned = null,
        ?SourceObservation $observation = null
    ): WishlistPlan {
        $typed = $record->typedPlan();
        if (
            $record->sourceType() !== WishlistMigrationParticipant::SOURCE_TYPE
            || !$typed instanceof WishlistPlan
        ) {
            throw new WishlistMigrationFailure(
                WishlistMigrationReason::InvalidTypedPlan,
                "Wishlist source record has no matching typed V2 plan."
            );
        }
        if (
            DeterministicJson::hash($typed->canonicalPayload())
                !== $record->payloadHash()
            || ($planned !== null && (
                !$planned->typedPlan() instanceof WishlistPlan
                || DeterministicJson::hash(
                    $planned->typedPlan()->canonicalPayload()
                ) !== $record->payloadHash()
            ))
            || ($observation !== null && (
                $observation->sourceType()
                    !== WishlistMigrationParticipant::SOURCE_TYPE
                || $observation->sourceId() !== $record->sourceId()
                || $observation->payloadHash() !== $record->payloadHash()
            ))
        ) {
            throw new WishlistMigrationFailure(
                WishlistMigrationReason::DivergentReplay,
                "Wishlist plan, source record and observation diverge."
            );
        }

        return $typed;
    }

    private function __construct()
    {
    }
}
