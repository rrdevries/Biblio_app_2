<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

enum QuarantineReason: string
{
    case InvalidIsbnClaim = "invalid_isbn_claim";
    case CanonicalIsbnIdentityConflict = "canonical_isbn_identity_conflict";
    case SourceIdentityConflict = "source_identity_conflict";
    case MissingRequiredTargetField = "missing_required_target_field";
    case OrphanReference = "orphan_reference";
    case ReadingTruthConflict = "reading_truth_conflict";
    case UnknownTaxonomyMapping = "unknown_taxonomy_mapping";
    case UnresolvedWorkIdentity = "unresolved_work_identity";
    case StructuralAmbiguity = "structural_ambiguity";
    case AmbiguousContributor = "ambiguous_contributor";
    case AmbiguousCirculationSemantics = "ambiguous_circulation_semantics";
    case UnsupportedTargetRepresentation = "unsupported_target_representation";
}
