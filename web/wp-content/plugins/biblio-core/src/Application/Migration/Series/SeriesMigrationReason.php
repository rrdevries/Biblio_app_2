<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Series;

enum SeriesMigrationReason: string
{
    case InvalidTypedPlan = "invalid_typed_series_plan";
    case MissingTargetReference = "missing_required_target_reference";
    case TargetCollision = "series_target_collision";
    case MembershipConflict = "work_series_membership_conflict";
    case DivergentReplay = "stale_divergent_replay";
}
