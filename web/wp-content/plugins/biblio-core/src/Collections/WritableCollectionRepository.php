<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

use Biblio\Core\Library\LibraryId;

interface WritableCollectionRepository extends CollectionRepository, CollectionMembershipArchivePort
{
    public function nextPositionForUpdate(LibraryId $libraryId): CollectionPosition;
    public function add(LibraryCollection $collection): void;
    public function findForUpdate(LibraryId $libraryId, CollectionId $collectionId): ?LibraryCollection;
    public function activeByNormalizedNameForUpdate(LibraryId $libraryId, NormalizedCollectionName $name): ?LibraryCollection;
    /** @return list<LibraryCollection> */
    public function activeForLibraryForUpdate(LibraryId $libraryId): array;
    public function replaceIfVersionMatches(LibraryCollection $replacement, CollectionVersion $expectedVersion): bool;
    /** @return list<CollectionMembership> */
    public function activeMembershipsForUpdate(LibraryId $libraryId, CollectionId $collectionId): array;
    public function addMembership(CollectionMembership $membership): void;
    public function replaceMembership(CollectionMembership $membership): void;
}
