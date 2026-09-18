<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

enum CurrentV1ContainedWorkMappingReason: string
{
    case MappingContractApplied = "contained_work_mapping_contract_applied";
    case ChildWorkPlanned = "contained_child_work_planned";
    case ContainmentPlanned = "catalog_work_containment_planned";
    case AuthorPlanned = "contained_author_planned";
    case ContributorPlanned = "contained_contributor_planned";
    case SeriesMembershipPlanned = "contained_series_membership_planned";
    case SeriesNameMissing = "contained_work_series_deferred";
    case IsbnDeferred = "contained_work_isbn_deferred";
    case InvalidStructure = "invalid_contained_work_structure";
    case UnresolvedParent = "unresolved_contained_parent_work";
    case AliasConflict = "contained_work_alias_conflict";
    case SeriesIdentityUnavailable = "contained_series_identity_unavailable";
}
