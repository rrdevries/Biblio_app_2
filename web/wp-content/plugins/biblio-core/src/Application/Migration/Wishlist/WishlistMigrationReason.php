<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Wishlist;

enum WishlistMigrationReason: string
{
    case InvalidTypedPlan = "invalid_typed_wishlist_plan";
    case InvalidHistoricalTimestamp = "invalid_historical_timestamp";
    case InvalidPreparedProvenance = "invalid_wishlist_prepared_provenance";
    case MissingWorkMapping = "missing_work_mapping";
    case WrongMappingTargetType = "wrong_mapping_target_type";
    case OwnerMismatch = "owner_mismatch";
    case ConflictingWishlistMapping = "conflicting_wishlist_mapping";
    case CardinalityConflict = "wishlist_cardinality_conflict";
    case DivergentReplay = "stale_divergent_replay";
}
