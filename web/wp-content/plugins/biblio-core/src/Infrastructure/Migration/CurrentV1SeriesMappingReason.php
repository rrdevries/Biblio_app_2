<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

enum CurrentV1SeriesMappingReason: string
{
    case MappingContractApplied = "series_mapping_contract_applied";
    case SeriesPlanned = "catalog_series_planned";
    case MembershipPlanned = "catalog_work_series_membership_planned";
    case SeriesNameMissing = "series_name_missing";
    case UnsafePosition = "series_position_not_safely_mappable";
    case ContainedWorkDeferred = "contained_work_series_deferred";
    case UnresolvedWork = "unresolved_series_work_identity";
    case InvalidStructure = "invalid_series_structure";
    case ConvergenceConflict = "converged_series_membership_conflict";
}
