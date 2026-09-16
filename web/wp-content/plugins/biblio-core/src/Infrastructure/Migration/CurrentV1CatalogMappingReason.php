<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

enum CurrentV1CatalogMappingReason: string
{
    case CatalogWorkEditionPlanned = "catalog_work_edition_planned";
    case UnknownIsbn = "unknown_isbn";
    case InvalidIsbn = "invalid_isbn";
    case InvalidIsbnEvidencePreserved = "invalid_isbn_evidence_preserved";
    case ConflictingIsbnEvidence = "conflicting_isbn_evidence";
    case DuplicateIsbnRepresentative = "duplicate_isbn_representative";
    case DuplicateIsbnAlias = "duplicate_isbn_alias";
    case DuplicateIsbnWorkConflict = "duplicate_isbn_work_conflict";
    case CatalogItemPlanned = "catalog_item_planned";
    case UnresolvedClassificationDependency = "unresolved_classification_dependency";
    case UnresolvedItemLocalDependency = "unresolved_item_local_dependency";
    case CatalogBookQuarantined = "catalog_book_quarantined";
    case OrphanCopyReference = "orphan_copy_reference";
    case DeferredVariantRelation = "deferred_variant_relation";
    case DeferredContainedWork = "deferred_contained_work";
    case DeferredEditionEvidence = "deferred_edition_evidence";
    case DeferredItemSourceNumbers = "deferred_item_source_numbers";
    case CatalogSourceEvidenceRetained = "catalog_source_evidence_retained";
    case ConservativeCatalogSubset = "conservative_catalog_subset";
    case ConservativeItemSubset = "conservative_item_subset";
    case MissingRequiredCatalogField = "missing_required_catalog_field";
}
