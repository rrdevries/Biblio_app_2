<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Assessments;

enum AssessmentMigrationReason: string
{
    case InvalidTypedRatingPlan = "invalid_typed_rating_plan";
    case InvalidTypedWrittenReviewPlan = "invalid_typed_written_review_plan";
    case MissingWorkMapping = "missing_work_mapping";
    case MissingReadingRoundMapping = "missing_reading_round_mapping";
    case WrongMappingTargetType = "wrong_mapping_target_type";
    case OwnerMismatch = "owner_mismatch";
    case InvalidAssessmentContext = "invalid_assessment_context";
    case InvalidReviewContent = "invalid_review_content";
    case InvalidHistoricalTimestamp = "invalid_historical_timestamp";
    case ConflictingAssessmentMapping = "conflicting_assessment_mapping";
    case AssessmentCardinalityConflict = "assessment_cardinality_conflict";
    case DivergentReplay = "stale_divergent_replay";
}
