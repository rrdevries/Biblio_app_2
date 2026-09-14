<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Library\LibraryId;

interface ItemLocalDetailsRepository
{
    public function find(LibraryId $libraryId, ItemId $itemId): ?ItemLocalDetails;

    /**
     * @param list<ItemId> $itemIds
     * @return array<string, ItemLocalDetails|null>
     */
    public function findMany(LibraryId $libraryId, array $itemIds): array;
}
