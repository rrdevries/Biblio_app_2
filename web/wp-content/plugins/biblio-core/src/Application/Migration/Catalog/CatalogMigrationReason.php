<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Catalog;

enum CatalogMigrationReason: string
{
    case InvalidTypedPlan = "invalid_typed_catalog_plan";
    case MissingTargetReference = "missing_required_target_reference";
    case UnsafeWorkMapping = "unsafe_work_mapping";
    case IsbnConflict = "isbn_conflict";
    case WorkEditionConflict = "work_edition_conflict";
    case CrossLibraryTarget = "cross_library_target";
    case ItemDuplicateConflict = "item_duplicate_conflict";
    case InvalidClassificationTarget = "invalid_classification_target";
    case InvalidItemLocalDetails = "invalid_item_local_details";
    case DivergentReplay = "stale_divergent_replay";
    case ArchivedItemConflict = "archived_item_conflict";
}
