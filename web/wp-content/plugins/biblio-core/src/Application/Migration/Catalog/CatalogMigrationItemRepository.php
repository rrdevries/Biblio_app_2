<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Catalog;

use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\WritableItemRepository;

interface CatalogMigrationItemRepository extends WritableItemRepository
{
    /** Migration-only identity check; ordinary reads remain Library-scoped. */
    public function findForMigration(ItemId $itemId): ?Item;
}
