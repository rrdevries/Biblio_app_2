<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reading;

enum ReadingRoundMigrationReason: string
{
    case InvalidTypedPlan = "invalid_typed_reading_round_plan";
    case MissingWorkMapping = "missing_work_mapping";
    case MissingSourceMapping = "missing_source_mapping";
    case ConflictingRoundMapping = "conflicting_round_mapping";
    case CrossTargetMapping = "cross_target_mapping";
    case DivergentReplay = "stale_divergent_replay";
}
