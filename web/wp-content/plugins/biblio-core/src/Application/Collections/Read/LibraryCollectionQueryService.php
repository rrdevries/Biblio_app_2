<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Collections\Read;

use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Collections\{CollectionId,CollectionRepository,LibraryCollection};
use Biblio\Core\Library\LibraryId;

final readonly class LibraryCollectionQueryService
{
    public function __construct(private LibraryContextQueryService $contexts, private CollectionRepository $collections) {}

    /** @return list<LibraryCollection> */
    public function activeCollections(LibraryId $libraryId): array
    { $this->contexts->get($libraryId); return $this->collections->activeForLibrary($libraryId); }

    /**
     * @param list<CollectionId> $collectionIds
     * @return array<string, LibraryCollection|null>
     */
    public function collections(LibraryId $libraryId, array $collectionIds): array
    { $this->contexts->get($libraryId); return $this->collections->findManyInLibrary($libraryId, $collectionIds); }

    /**
     * @param list<ItemId> $itemIds
     * @return array<string, list<CollectionId>>
     */
    public function activeCollectionsForItems(LibraryId $libraryId, array $itemIds): array
    { $this->contexts->get($libraryId); return $this->collections->activeCollectionIdsForItems($libraryId, $itemIds); }

    /**
     * @param list<CollectionId> $collectionIds
     * @return array<string, list<ItemId>>
     */
    public function activeItemsForCollections(LibraryId $libraryId, array $collectionIds): array
    { $this->contexts->get($libraryId); return $this->collections->activeItemIdsForCollections($libraryId, $collectionIds); }

    /**
     * @param list<CollectionId> $collectionIds
     * @return array<string, int>
     */
    public function activeCounts(LibraryId $libraryId, array $collectionIds): array
    { $this->contexts->get($libraryId); return $this->collections->activeCountsForCollections($libraryId, $collectionIds); }

    /**
     * @param list<ItemId> $itemIds
     * @return array<string, list<CollectionId>>
     */
    public function previousCollectionsForArchivedItems(LibraryId $libraryId, array $itemIds): array
    { $this->contexts->get($libraryId); return $this->collections->previousCollectionIdsForArchivedItems($libraryId, $itemIds); }
}
