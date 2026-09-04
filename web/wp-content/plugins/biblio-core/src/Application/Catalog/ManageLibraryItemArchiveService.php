<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Audit\ActivityEventAppender;
use Biblio\Core\Catalog\{Item,ItemArchiveClock,ItemArchivePeriod,ItemArchiveReason,ItemArchiveStale,ItemArchiveTransitionUnavailable,ItemId,ItemStatus,ItemVersion,WritableItemArchiveRepository};
use Biblio\Core\Library\{LibraryContext,LibraryId};

final readonly class ManageLibraryItemArchiveService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private LibraryAccessService $libraryAccess,
        private WritableItemArchiveRepository $repository,
        private ItemArchiveClock $clock,
        private ItemArchiveActivity $activity,
        private ActivityEventAppender $activityEvents,
        private TransactionManager $transactions
    ) {}

    public function archive(LibraryId $libraryId, ItemId $itemId, ItemArchiveReason $reason, ItemVersion $expectedVersion): Item
    {
        $context = $this->authorize($libraryId);
        return $this->transactions->run(function () use ($context, $itemId, $reason, $expectedVersion): Item {
            $current = $this->availableForUpdate($context, $itemId);
            $open = $this->repository->openPeriod($itemId, $context->libraryId());

            if ($current->status() === ItemStatus::Archived) {
                if ($open !== null && $open->reason() === $reason) { return $current; }
                throw new ItemArchiveTransitionUnavailable();
            }
            if ($open !== null) { throw new ItemArchiveTransitionUnavailable(); }
            if (!$current->version()->equals($expectedVersion)) { throw new ItemArchiveStale($current); }

            $replacement = $current->archive();
            $period = new ItemArchivePeriod($context->libraryId(), $itemId, $replacement->version(), $reason, $this->clock->now());
            if (!$this->repository->saveArchive($replacement, $expectedVersion, $period)) { throw new ItemArchiveStale($current); }
            $this->activityEvents->append($this->activity->archived($context->userId(), $replacement, $reason));
            return $replacement;
        });
    }

    public function restore(LibraryId $libraryId, ItemId $itemId, ItemVersion $expectedVersion): Item
    {
        $context = $this->authorize($libraryId);
        return $this->transactions->run(function () use ($context, $itemId, $expectedVersion): Item {
            $current = $this->availableForUpdate($context, $itemId);
            $open = $this->repository->openPeriod($itemId, $context->libraryId());
            if ($current->status() === ItemStatus::Active) {
                if ($open !== null) { throw new ItemArchiveTransitionUnavailable(); }
                return $current;
            }
            if (!$current->version()->equals($expectedVersion)) { throw new ItemArchiveStale($current); }
            if ($open === null) { throw new ItemArchiveTransitionUnavailable(); }

            $replacement = $current->restore();
            $closed = new ItemArchivePeriod($open->libraryId(), $open->itemId(), $open->archiveVersion(), $open->reason(), $open->archivedAt(), $replacement->version(), $this->clock->now());
            if (!$this->repository->saveRestore($replacement, $expectedVersion, $closed)) { throw new ItemArchiveStale($current); }
            $this->activityEvents->append($this->activity->restored($context->userId(), $replacement));
            return $replacement;
        });
    }

    private function authorize(LibraryId $libraryId): LibraryContext
    {
        $context = new LibraryContext($libraryId, $this->authenticatedUser->requireUserId());
        if (!$this->libraryAccess->canManageCatalogItems($context)) { throw new ItemArchiveNotAvailable(); }
        return $context;
    }

    private function availableForUpdate(LibraryContext $context, ItemId $itemId): Item
    {
        $item = $this->repository->findItemForUpdate($itemId, $context->libraryId());
        if ($item === null || !$item->libraryId()->equals($context->libraryId())) { throw new ItemArchiveNotAvailable(); }
        return $item;
    }
}
