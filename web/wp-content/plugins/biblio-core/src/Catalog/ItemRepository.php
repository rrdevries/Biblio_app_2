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
}
