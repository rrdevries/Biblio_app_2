<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Library\LibraryId;

interface LocationRepository
{
    /** @return list<LibraryLocation> */
    public function forLibrary(LibraryId $libraryId): array;

    /**
     * @param list<ItemId> $itemIds
     * @return array<string, LibraryLocation|null>
     */
    public function forItems(LibraryId $libraryId, array $itemIds): array;

    /**
     * @param list<LocationId> $locationIds
     * @return array<string, list<ItemId>>
     */
    public function itemIdsForLocations(LibraryId $libraryId, array $locationIds): array;
}
