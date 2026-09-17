<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

enum CurrentV1AssessmentMappingReason: string
{
    case MappingContractApplied = "assessment_mapping_contract_applied";
    case RatingPlanned = "historical_rating_planned";
    case WrittenReviewPlanned = "historical_written_review_planned";
    case ReflectionTargetNotAvailable = "reflection_target_not_available";
    case InvalidRating = "invalid_rating_value";
    case InvalidReviewStructure = "invalid_review_structure";
    case InvalidReviewContent = "invalid_review_content";
    case InvalidReviewTimestamp = "invalid_review_timestamp";
    case InvalidReflection = "invalid_reflection_structure";
    case UnresolvedWorkIdentity = "unresolved_assessment_work_identity";
    case ConvergedRatingConflict = "converged_rating_cardinality_conflict";
    case ConvergedReviewConflict = "converged_review_cardinality_conflict";
}
