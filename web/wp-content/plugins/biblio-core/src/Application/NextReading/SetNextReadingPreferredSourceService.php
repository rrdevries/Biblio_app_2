<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\{EditionRepository,ItemId};
use Biblio\Core\Library\LibraryId;
use Biblio\Core\NextReading\{NextReadingClock,NextReadingEntry,NextReadingEntryId,NextReadingList,NextReadingListStale,NextReadingListVersion,PreferredReadingSource,PreferredReadingSourceUnavailable,WritableNextReadingRepository};

final readonly class SetNextReadingPreferredSourceService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WritableNextReadingRepository $repository,
        private GetAccessibleLibraryItemService $items,
        private EditionRepository $editions,
        private GetOwnedExternalLoanService $loans,
        private NextReadingClock $clock,
        private TransactionManager $transactions
    ) {
    }

    public function setLibraryItem(
        NextReadingEntryId $entryId,
        NextReadingListVersion $expected,
        LibraryId $libraryId,
        ItemId $itemId
    ): NextReadingList {
        return $this->change($entryId, $expected, function (NextReadingEntry $entry) use ($libraryId, $itemId): PreferredReadingSource {
            $accessible = $this->items->get($libraryId, $itemId);
            $item = $accessible?->item();
            $edition = $item === null ? null : $this->editions->find($item->editionId());
            if ($edition === null || !$edition->workId()->equals($entry->workId())) {
                throw new PreferredReadingSourceUnavailable();
            }
            return PreferredReadingSource::libraryItem($itemId, $libraryId);
        });
    }

    public function setExternalLoan(
        NextReadingEntryId $entryId,
        NextReadingListVersion $expected,
        ExternalLoanId $externalLoanId
    ): NextReadingList {
        return $this->change($entryId, $expected, function (NextReadingEntry $entry) use ($externalLoanId): PreferredReadingSource {
            $loan = $this->loans->get($externalLoanId);
            if ($loan === null || !$loan->workId()->equals($entry->workId())) {
                throw new PreferredReadingSourceUnavailable();
            }
            return PreferredReadingSource::externalLoan($externalLoanId);
        });
    }

    public function clear(
        NextReadingEntryId $entryId,
        NextReadingListVersion $expected
    ): NextReadingList {
        return $this->change($entryId, $expected, static fn (): null => null);
    }

    /** @param callable(\Biblio\Core\NextReading\NextReadingEntry): ?PreferredReadingSource $source */
    private function change(
        NextReadingEntryId $entryId,
        NextReadingListVersion $expected,
        callable $source
    ): NextReadingList {
        $actorId = $this->authenticatedUser->requireUserId();
        return $this->transactions->run(function () use (
            $actorId,
            $entryId,
            $expected,
            $source
        ): NextReadingList {
            $current = $this->repository->lockForUser($actorId, $this->clock->now());
            $entry = $current->find($entryId);
            if ($entry === null) {
                throw new \Biblio\Core\NextReading\NextReadingEntryNotAvailable();
            }
            $next = $current->withPreferredSource($entryId, $source($entry));
            if ($next === $current) {
                return $current;
            }
            if (!$current->version()->equals($expected)) {
                throw new NextReadingListStale($current);
            }
            $now = $this->clock->now();
            $this->repository->replaceEntries(
                $actorId,
                $next->entries(),
                $current->version(),
                $next->version(),
                $now
            );
            return $next;
        });
    }
}
