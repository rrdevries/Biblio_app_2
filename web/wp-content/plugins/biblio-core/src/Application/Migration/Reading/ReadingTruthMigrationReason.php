<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reading;

enum ReadingTruthMigrationReason: string
{
    case InvalidTypedPlan = "invalid_typed_reading_truth_plan";
    case MissingWorkMapping = "missing_work_mapping";
    case ConflictingTruthMapping = "conflicting_reading_truth_mapping";
    case CrossTargetMapping = "cross_target_mapping";
    case ContradictoryTruth = "contradictory_reading_truth";
    case DivergentReplay = "stale_divergent_replay";
}
