<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Library\LibraryId;

interface CollectionRepository
{
    /** @return list<LibraryCollection> */
    public function activeForLibrary(LibraryId $libraryId): array;

    /**
     * @param list<CollectionId> $collectionIds
     * @return array<string, LibraryCollection|null>
     */
    public function findManyInLibrary(LibraryId $libraryId, array $collectionIds): array;

    /**
     * @param list<ItemId> $itemIds
     * @return array<string, list<CollectionId>>
     */
    public function activeCollectionIdsForItems(LibraryId $libraryId, array $itemIds): array;

    /**
     * @param list<CollectionId> $collectionIds
     * @return array<string, list<ItemId>>
     */
    public function activeItemIdsForCollections(LibraryId $libraryId, array $collectionIds): array;

    /**
     * @param list<CollectionId> $collectionIds
     * @return array<string, int>
     */
    public function activeCountsForCollections(LibraryId $libraryId, array $collectionIds): array;

    /**
     * @param list<ItemId> $itemIds
     * @return array<string, list<CollectionId>>
     */
    public function previousCollectionIdsForArchivedItems(LibraryId $libraryId, array $itemIds): array;
}
