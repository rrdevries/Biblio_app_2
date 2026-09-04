<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Collections;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Catalog\{ItemId,ItemRepository,ItemStatus};
use Biblio\Core\Collections\{CollectionClock,CollectionDescription,CollectionId,CollectionIdGenerator,CollectionItemNotAvailable,CollectionItemPosition,CollectionMembership,CollectionMembershipConflict,CollectionMembershipEndReason,CollectionMembershipIdGenerator,CollectionName,CollectionNameConflict,CollectionNameNormalizer,CollectionNotAvailable,CollectionPosition,CollectionStale,CollectionStatus,CollectionTransitionUnavailable,CollectionVersion,LibraryCollection,WritableCollectionRepository};
use Biblio\Core\Library\{LibraryContext,LibraryId,LibraryMutationLock};

final readonly class ManageLibraryCollectionsService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private LibraryAccessService $access,
        private ItemRepository $items,
        private WritableCollectionRepository $collections,
        private LibraryMutationLock $libraryLock,
        private CollectionNameNormalizer $names,
        private CollectionIdGenerator $collectionIds,
        private CollectionMembershipIdGenerator $membershipIds,
        private CollectionClock $clock,
        private TransactionManager $transactions
    ) {}

    public function create(LibraryId $libraryId, CollectionName $name, ?CollectionDescription $description = null): LibraryCollection
    {
        $this->authorize($libraryId);
        $normalized = $this->names->normalize($name);
        return $this->transactions->run(function () use ($libraryId, $name, $normalized, $description): LibraryCollection {
            $this->libraryLock->acquire($libraryId);
            if ($this->collections->activeByNormalizedNameForUpdate($libraryId, $normalized) !== null) { throw new CollectionNameConflict(); }
            $collection = LibraryCollection::create($this->collectionIds->next(), $libraryId, $name, $normalized, $description, $this->collections->nextPositionForUpdate($libraryId), $this->clock->now());
            $this->collections->add($collection);
            return $collection;
        });
    }

    public function update(LibraryId $libraryId, CollectionId $collectionId, CollectionVersion $expected, CollectionName $name, ?CollectionDescription $description = null): LibraryCollection
    {
        $this->authorize($libraryId);
        $normalized = $this->names->normalize($name);
        return $this->transactions->run(function () use ($libraryId, $collectionId, $expected, $name, $normalized, $description): LibraryCollection {
            $this->libraryLock->acquire($libraryId);
            $current = $this->requireCollection($libraryId, $collectionId);
            if ($current->status() !== CollectionStatus::Active) { throw new CollectionTransitionUnavailable(); }
            $replacement = $current->updateDetails($name, $normalized, $description, $this->clock->now());
            if ($replacement === $current) { return $current; }
            $this->assertExpected($current, $expected);
            $named = $this->collections->activeByNormalizedNameForUpdate($libraryId, $normalized);
            if ($named !== null && !$named->id()->equals($collectionId)) { throw new CollectionNameConflict(); }
            $this->replace($replacement, $expected);
            return $replacement;
        });
    }

    public function archive(LibraryId $libraryId, CollectionId $collectionId, CollectionVersion $expected): LibraryCollection
    {
        return $this->changeStatus($libraryId, $collectionId, $expected, CollectionStatus::Archived);
    }

    public function restore(LibraryId $libraryId, CollectionId $collectionId, CollectionVersion $expected): LibraryCollection
    {
        return $this->changeStatus($libraryId, $collectionId, $expected, CollectionStatus::Active);
    }

    /**
     * @param list<CollectionId> $orderedIds
     * @return list<LibraryCollection>
     */
    public function reorderCollections(LibraryId $libraryId, array $orderedIds): array
    {
        $this->authorize($libraryId);
        $this->assertUniqueIds(array_map(static fn (CollectionId $id): string => $id->value(), $orderedIds));
        return $this->transactions->run(function () use ($libraryId, $orderedIds): array {
            $this->libraryLock->acquire($libraryId);
            $current = $this->collections->activeForLibraryForUpdate($libraryId);
            $byId = [];
            foreach ($current as $collection) { $byId[$collection->id()->value()] = $collection; }
            if (count($byId) !== count($orderedIds)) { throw new CollectionNotAvailable(); }
            $now = $this->clock->now();
            $result = [];
            foreach ($orderedIds as $offset => $id) {
                $collection = $byId[$id->value()] ?? throw new CollectionNotAvailable();
                $replacement = $collection->reposition(new CollectionPosition($offset + 1), $now);
                if ($replacement !== $collection) { $this->replace($replacement, $collection->version()); }
                $result[] = $replacement;
            }
            return $result;
        });
    }

    public function addItem(LibraryId $libraryId, CollectionId $collectionId, CollectionVersion $expected, ItemId $itemId): LibraryCollection
    {
        return $this->mutateItems($libraryId, $collectionId, $expected, function (array $memberships) use ($itemId): array {
            foreach ($memberships as $membership) {
                if ($membership->itemId()->equals($itemId)) { throw new CollectionMembershipConflict(); }
            }
            return [...array_map(static fn (CollectionMembership $membership): ItemId => $membership->itemId(), $memberships), $itemId];
        });
    }

    public function removeItem(LibraryId $libraryId, CollectionId $collectionId, CollectionVersion $expected, ItemId $itemId): LibraryCollection
    {
        return $this->mutateItems($libraryId, $collectionId, $expected, function (array $memberships) use ($itemId): array {
            $found = false;
            $ids = [];
            foreach ($memberships as $membership) {
                if ($membership->itemId()->equals($itemId)) { $found = true; continue; }
                $ids[] = $membership->itemId();
            }
            if (!$found) { throw new CollectionMembershipConflict(); }
            return $ids;
        });
    }

    /** @param list<ItemId> $orderedItemIds */
    public function saveItems(LibraryId $libraryId, CollectionId $collectionId, CollectionVersion $expected, array $orderedItemIds): LibraryCollection
    {
        return $this->mutateItems($libraryId, $collectionId, $expected, static fn (): array => $orderedItemIds);
    }

    /** @param callable(list<CollectionMembership>): list<ItemId> $desired */
    private function mutateItems(LibraryId $libraryId, CollectionId $collectionId, CollectionVersion $expected, callable $desired): LibraryCollection
    {
        $this->authorize($libraryId);
        return $this->transactions->run(function () use ($libraryId, $collectionId, $expected, $desired): LibraryCollection {
            $current = $this->requireCollection($libraryId, $collectionId);
            if ($current->status() !== CollectionStatus::Active) { throw new CollectionTransitionUnavailable(); }
            $this->assertExpected($current, $expected);
            $memberships = $this->collections->activeMembershipsForUpdate($libraryId, $collectionId);
            $itemIds = $desired($memberships);
            $this->assertUniqueIds(array_map(static fn (ItemId $id): string => $id->value(), $itemIds));
            $storedItems = $this->items->findManyInLibrary($libraryId, $itemIds);
            foreach ($itemIds as $itemId) {
                $item = $storedItems[$itemId->value()] ?? null;
                if ($item === null || $item->status() !== ItemStatus::Active) { throw new CollectionItemNotAvailable(); }
            }
            $currentIds = array_map(static fn (CollectionMembership $membership): string => $membership->itemId()->value(), $memberships);
            $desiredIds = array_map(static fn (ItemId $id): string => $id->value(), $itemIds);
            if ($currentIds === $desiredIds) { return $current; }
            $now = $this->clock->now();
            $replacement = $current->contentChanged($now);
            $this->replace($replacement, $expected);
            $desiredPositions = array_flip($desiredIds);
            $existing = [];
            foreach ($memberships as $membership) {
                $itemKey = $membership->itemId()->value();
                if (!array_key_exists($itemKey, $desiredPositions)) {
                    $this->collections->replaceMembership($membership->deactivate(CollectionMembershipEndReason::Removed, $now));
                    continue;
                }
                $positioned = $membership->reposition(new CollectionItemPosition((int) $desiredPositions[$itemKey] + 1));
                if ($positioned !== $membership) { $this->collections->replaceMembership($positioned); }
                $existing[$itemKey] = true;
            }
            foreach ($itemIds as $offset => $itemId) {
                if (isset($existing[$itemId->value()])) { continue; }
                $this->collections->addMembership(CollectionMembership::active($this->membershipIds->next(), $libraryId, $collectionId, $itemId, new CollectionItemPosition($offset + 1), $now));
            }
            return $replacement;
        });
    }

    private function changeStatus(LibraryId $libraryId, CollectionId $collectionId, CollectionVersion $expected, CollectionStatus $target): LibraryCollection
    {
        $this->authorize($libraryId);
        return $this->transactions->run(function () use ($libraryId, $collectionId, $expected, $target): LibraryCollection {
            $this->libraryLock->acquire($libraryId);
            $current = $this->requireCollection($libraryId, $collectionId);
            if ($current->status() === $target) { return $current; }
            $this->assertExpected($current, $expected);
            if ($target === CollectionStatus::Active) {
                $named = $this->collections->activeByNormalizedNameForUpdate($libraryId, $current->normalizedName());
                if ($named !== null && !$named->id()->equals($collectionId)) { throw new CollectionNameConflict(); }
            }
            $replacement = $target === CollectionStatus::Active ? $current->restore($this->clock->now()) : $current->archive($this->clock->now());
            $this->replace($replacement, $expected);
            return $replacement;
        });
    }

    private function authorize(LibraryId $libraryId): void
    {
        $context = new LibraryContext($libraryId, $this->authenticatedUser->requireUserId());
        if (!$this->access->canManageCollections($context)) { throw new CollectionNotAvailable(); }
    }

    private function requireCollection(LibraryId $libraryId, CollectionId $collectionId): LibraryCollection
    {
        $collection = $this->collections->findForUpdate($libraryId, $collectionId);
        if ($collection === null || !$collection->libraryId()->equals($libraryId)) { throw new CollectionNotAvailable(); }
        return $collection;
    }

    private function assertExpected(LibraryCollection $current, CollectionVersion $expected): void
    {
        if (!$current->version()->equals($expected)) { throw new CollectionStale($current); }
    }

    private function replace(LibraryCollection $replacement, CollectionVersion $expected): void
    {
        if (!$this->collections->replaceIfVersionMatches($replacement, $expected)) { throw new CollectionStale($replacement); }
    }

    /** @param list<string> $ids */
    private function assertUniqueIds(array $ids): void
    {
        if (count($ids) !== count(array_unique($ids))) { throw new CollectionMembershipConflict(); }
    }
}
