<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Library\LibraryId;

interface WritableItemLocalDetailsRepository extends ItemLocalDetailsRepository
{
    public function findForUpdate(LibraryId $libraryId, ItemId $itemId): ?ItemLocalDetails;
    public function add(ItemLocalDetails $details): void;
    public function replaceIfVersionMatches(
        ItemLocalDetails $replacement,
        ItemLocalDetailsVersion $expectedVersion
    ): bool;
}
