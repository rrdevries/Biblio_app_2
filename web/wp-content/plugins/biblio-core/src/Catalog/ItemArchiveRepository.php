<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Library\LibraryId;

interface ItemArchiveRepository
{
    /**
     * @param list<ItemId> $itemIds
     * @return array<string, list<ItemArchivePeriod>>
     */
    public function periodsForItems(LibraryId $libraryId, array $itemIds): array;
}
