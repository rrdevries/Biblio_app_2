<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog;

use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemLocalDetails;
use Biblio\Core\Catalog\ItemLocalDetailsStale;
use Biblio\Core\Catalog\ItemLocalDetailsState;
use Biblio\Core\Catalog\ItemLocalDetailsVersion;
use Biblio\Core\Catalog\ItemRepository;
use Biblio\Core\Catalog\WritableItemLocalDetailsRepository;
use Biblio\Core\Library\LibraryId;

/** Source-neutral participant which deliberately owns no transaction. */
final readonly class ItemLocalDetailsRecorder
{
    public function __construct(
        private ItemRepository $items,
        private WritableItemLocalDetailsRepository $details
    ) {
    }

    public function recordForLibrary(
        LibraryId $targetLibraryId,
        ItemId $itemId,
        ItemLocalDetailsState $state,
        ?ItemLocalDetailsVersion $expectedVersion = null
    ): ?ItemLocalDetails {
        $item = $this->items->findInLibrary($itemId, $targetLibraryId);
        if ($item === null || !$item->libraryId()->equals($targetLibraryId)) {
            throw new ItemLocalDetailsNotAvailable();
        }

        // The first read is deliberately non-locking: locking a missing unique
        // key would turn concurrent create into a gap-lock deadlock. Inserts
        // race on the composite primary key; a loser then performs a current
        // locking read and converges only on exact state equality.
        $current = $this->details->find($targetLibraryId, $itemId);
        if ($current === null) {
            if ($expectedVersion !== null) {
                throw new ItemLocalDetailsStale();
            }
            if ($state->isUnknown()) {
                return null;
            }

            $created = ItemLocalDetails::create($targetLibraryId, $itemId, $state);
            try {
                $this->details->add($created);
                return $created;
            } catch (ItemLocalDetailsStale) {
                $winner = $this->details->findForUpdate($targetLibraryId, $itemId);
                if ($winner !== null && $winner->state()->equals($state)) {
                    return $winner;
                }
                throw new ItemLocalDetailsStale();
            }
        }

        // A plain read only detects the existing-row path without taking a
        // missing-key gap lock. Refresh and lock that row before replay
        // equality or CAS so a committed concurrent replacement cannot be
        // acknowledged from a stale snapshot.
        $current = $this->details->findForUpdate($targetLibraryId, $itemId);
        if ($current === null) {
            throw new ItemLocalDetailsStale();
        }

        if ($current->state()->equals($state)) {
            return $current;
        }
        if ($expectedVersion === null || !$current->version()->equals($expectedVersion)) {
            throw new ItemLocalDetailsStale();
        }

        $replacement = $current->replace($state);
        if (!$this->details->replaceIfVersionMatches($replacement, $expectedVersion)) {
            throw new ItemLocalDetailsStale();
        }

        return $replacement;
    }
}
