<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Library\LibraryId;

interface WritableItemArchiveRepository extends ItemArchiveRepository
{
    public function findItemForUpdate(ItemId $itemId, LibraryId $libraryId): ?Item;
    public function saveArchive(Item $replacement, ItemVersion $expectedVersion, ItemArchivePeriod $period): bool;
    public function saveRestore(Item $replacement, ItemVersion $expectedVersion, ItemArchivePeriod $openPeriod): bool;
    public function openPeriod(ItemId $itemId, LibraryId $libraryId): ?ItemArchivePeriod;
}
