<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\{EditionRepository,ItemId,WorkId,WorkRepository};
use Biblio\Core\Library\LibraryId;
use Biblio\Core\NextReading\{NextReadingList,NextReadingWorkUnavailable,PreferredReadingSource,PreferredReadingSourceUnavailable};

final readonly class AddNextReadingEntryService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WorkRepository $works,
        private GetAccessibleLibraryItemService $items,
        private EditionRepository $editions,
        private GetOwnedExternalLoanService $loans,
        private NextReadingMutation $mutation
    ) {
    }

    public function add(WorkId $workId): NextReadingList
    {
        $actorId = $this->authenticatedUser->requireUserId();
        if ($this->works->find($workId) === null) {
            throw new NextReadingWorkUnavailable();
        }
        return $this->mutation->append($actorId, $workId, null);
    }

    public function addWithLibraryItem(
        WorkId $workId,
        LibraryId $libraryId,
        ItemId $itemId
    ): NextReadingList {
        $actorId = $this->authenticatedUser->requireUserId();
        $accessible = $this->items->get($libraryId, $itemId);
        $item = $accessible?->item();
        $edition = $item === null ? null : $this->editions->find($item->editionId());
        if ($edition === null || !$edition->workId()->equals($workId)) {
            throw new PreferredReadingSourceUnavailable();
        }
        return $this->mutation->append(
            $actorId,
            $workId,
            PreferredReadingSource::libraryItem($itemId, $libraryId)
        );
    }

    public function addWithExternalLoan(
        WorkId $workId,
        ExternalLoanId $externalLoanId
    ): NextReadingList {
        $actorId = $this->authenticatedUser->requireUserId();
        $loan = $this->loans->get($externalLoanId);
        if ($loan === null || !$loan->workId()->equals($workId)) {
            throw new PreferredReadingSourceUnavailable();
        }
        return $this->mutation->append(
            $actorId,
            $workId,
            PreferredReadingSource::externalLoan($externalLoanId)
        );
    }
}
