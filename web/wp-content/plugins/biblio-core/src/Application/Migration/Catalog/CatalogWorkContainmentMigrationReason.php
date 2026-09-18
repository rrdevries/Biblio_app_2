<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Catalog;

enum CatalogWorkContainmentMigrationReason: string
{
    case InvalidTypedPlan = "invalid_typed_containment_plan";
    case MissingTargetReference = "missing_required_target_reference";
    case TargetCollision = "work_containment_target_collision";
    case RelationConflict = "work_containment_conflict";
    case DivergentReplay = "stale_divergent_replay";
}
