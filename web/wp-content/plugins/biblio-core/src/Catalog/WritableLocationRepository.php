<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Library\LibraryId;

interface WritableLocationRepository extends LocationRepository
{
    public function save(LibraryLocation $location): void;

    public function assignToItem(
        LibraryId $libraryId,
        ItemId $itemId,
        ?LocationId $locationId
    ): void;
}
