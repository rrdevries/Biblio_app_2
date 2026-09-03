<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Library\LibraryId;

interface LibraryItemMetadataRepository
{
    /**
     * @param list<ItemId> $itemIds
     * @return array<string, InventoryNumber|null>
     */
    public function inventoryNumbersForItems(
        LibraryId $libraryId,
        array $itemIds
    ): array;
}
