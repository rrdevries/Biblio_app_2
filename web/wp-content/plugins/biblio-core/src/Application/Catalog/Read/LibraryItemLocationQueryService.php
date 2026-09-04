<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Catalog\{ItemId,LibraryLocation,LocationId,LocationRepository};
use Biblio\Core\Library\LibraryId;

final readonly class LibraryItemLocationQueryService
{
    public function __construct(
        private LibraryContextQueryService $libraryContexts,
        private LocationRepository $locations
    ) {
    }

    /** @return list<LibraryLocation> */
    public function locations(LibraryId $libraryId): array
    {
        $this->libraryContexts->get($libraryId);
        return $this->locations->forLibrary($libraryId);
    }

    /**
     * @param list<ItemId> $itemIds
     * @return array<string, LibraryLocation|null>
     */
    public function locationsForItems(LibraryId $libraryId, array $itemIds): array
    {
        $this->libraryContexts->get($libraryId);
        return $this->locations->forItems($libraryId, $itemIds);
    }

    /**
     * @param list<LocationId> $locationIds
     * @return array<string, list<ItemId>>
     */
    public function itemIdsForLocations(LibraryId $libraryId, array $locationIds): array
    {
        $this->libraryContexts->get($libraryId);
        return $this->locations->itemIdsForLocations($libraryId, $locationIds);
    }
}
