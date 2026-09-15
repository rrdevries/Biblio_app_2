<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Circulation;

enum CirculationMigrationReason: string
{
    case InvalidTypedPlan = "invalid_typed_circulation_plan";
    case ProductTargetDeferred = "circulation_product_target_deferred";
}
