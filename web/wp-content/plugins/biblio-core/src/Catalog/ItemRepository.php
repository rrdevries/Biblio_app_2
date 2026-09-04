<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Library\LibraryId;

interface ItemRepository
{
    public function findInLibrary(
        ItemId $itemId,
        LibraryId $libraryId
    ): ?Item;

    /**
     * @param list<ItemId> $itemIds
     * @return array<string, Item|null>
     */
    public function findManyInLibrary(
        LibraryId $libraryId,
        array $itemIds
    ): array;
}
