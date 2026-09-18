<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

enum CurrentV1WishlistMappingReason: string
{
    case MappingContractApplied = "wishlist_mapping_contract_applied";
    case WorkOnlyPlanned = "wishlist_work_only_planned";
    case AuxiliaryEvidencePreserved = "wishlist_auxiliary_evidence_preserved";
    case InvalidSourceIdentity = "invalid_wishlist_source_identity";
    case InvalidSourceStructure = "invalid_wishlist_source_structure";
    case UnmatchedParentBook = "unmatched_wishlist_parent_book";
    case UnresolvedWorkIdentity = "unresolved_wishlist_work_identity";
    case UnreviewedType = "unreviewed_wishlist_type";
    case InvalidLifecycle = "invalid_wishlist_lifecycle";
    case UnsupportedFulfillment = "unsupported_wishlist_fulfillment";
    case InvalidTimestamp = "invalid_wishlist_timestamp";
    case InvalidAuxiliaryEvidence = "invalid_wishlist_auxiliary_evidence";
}
