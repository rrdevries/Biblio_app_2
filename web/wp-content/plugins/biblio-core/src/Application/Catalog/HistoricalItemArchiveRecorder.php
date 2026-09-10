<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog;

use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemArchivePeriod;
use Biblio\Core\Catalog\ItemArchiveStale;
use Biblio\Core\Catalog\ItemArchiveTransitionUnavailable;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemStatus;
use Biblio\Core\Catalog\ItemVersion;
use Biblio\Core\Catalog\PreservedHistoricalArchiveReason;
use Biblio\Core\Catalog\WritableItemArchiveRepository;
use Biblio\Core\Collections\CollectionMembershipArchivePort;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;

/**
 * Source-neutral participant for an existing transaction.
 *
 * Migration orchestration supplies the explicitly validated target Library.
 * Normal V2 actions continue to use ManageLibraryItemArchiveService.
 */
final readonly class HistoricalItemArchiveRecorder
{
    public function __construct(
        private WritableItemArchiveRepository $repository,
        private CollectionMembershipArchivePort $collectionMemberships
    ) {
    }

    public function recordForLibrary(
        LibraryId $targetLibraryId,
        ItemId $itemId,
        PreservedHistoricalArchiveReason $reason,
        DateTimeImmutable $archivedAt,
        ItemVersion $expectedVersion
    ): Item {
        $current = $this->repository->findItemForUpdate(
            $itemId,
            $targetLibraryId
        );
        if (
            $current === null
            || !$current->libraryId()->equals($targetLibraryId)
        ) {
            throw new ItemArchiveNotAvailable();
        }

        $open = $this->repository->openPeriod($itemId, $targetLibraryId);
        if ($current->status() === ItemStatus::Archived) {
            if (
                $open !== null
                && $open->reason()->equals($reason)
                && $open->archivedAt() == $archivedAt
            ) {
                return $current;
            }

            throw new ItemArchiveTransitionUnavailable();
        }
        if ($open !== null) {
            throw new ItemArchiveTransitionUnavailable();
        }
        if (!$current->version()->equals($expectedVersion)) {
            throw new ItemArchiveStale($current);
        }

        $replacement = $current->archive();
        $period = new ItemArchivePeriod(
            $targetLibraryId,
            $itemId,
            $replacement->version(),
            $reason,
            $archivedAt
        );
        if (!$this->repository->saveArchive(
            $replacement,
            $expectedVersion,
            $period
        )) {
            throw new ItemArchiveStale($current);
        }

        $this->collectionMemberships->deactivateForArchivedItem(
            $targetLibraryId,
            $itemId,
            $archivedAt
        );

        return $replacement;
    }
}
