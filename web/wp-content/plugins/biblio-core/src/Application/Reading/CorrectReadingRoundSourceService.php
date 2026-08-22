<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\EditionRepository;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundClock;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundNotAvailable;
use Biblio\Core\Reading\ReadingRoundSourceCorrectionUnavailable;
use Biblio\Core\Reading\ReadingRoundStale;
use Biblio\Core\Reading\ReadingRoundVersion;
use Biblio\Core\Reading\ReadingSource;
use Biblio\Core\Reading\WritableReadingRoundRepository;

final readonly class CorrectReadingRoundSourceService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WritableReadingRoundRepository $rounds,
        private GetAccessibleLibraryItemService $accessibleItems,
        private EditionRepository $editions,
        private GetOwnedExternalLoanService $ownedLoans,
        private ReadingRoundClock $clock,
        private TransactionManager $transactions
    ) {
    }

    public function correctToLibraryItem(
        ReadingRoundId $id,
        ReadingRoundVersion $expectedVersion,
        LibraryId $libraryId,
        ItemId $itemId
    ): ReadingRound {
        return $this->correct(
            $id,
            $expectedVersion,
            ReadingSource::libraryItem($itemId),
            function () use ($libraryId, $itemId): ?WorkId {
                $accessible = $this->accessibleItems->get($libraryId, $itemId);

                if ($accessible === null || !$accessible->canUseAsDirectSource()) {
                    return null;
                }

                $edition = $this->editions->find(
                    $accessible->item()->editionId()
                );

                return $edition?->workId();
            }
        );
    }

    public function correctToExternalLoan(
        ReadingRoundId $id,
        ReadingRoundVersion $expectedVersion,
        ExternalLoanId $loanId
    ): ReadingRound {
        return $this->correct(
            $id,
            $expectedVersion,
            ReadingSource::externalLoan($loanId),
            fn (): ?WorkId => $this->ownedLoans->get($loanId)?->workId()
        );
    }

    public function correctToUnknown(
        ReadingRoundId $id,
        ReadingRoundVersion $expectedVersion,
        bool $confirmRecordedSourceWasWrong
    ): ReadingRound {
        return $this->correct(
            $id,
            $expectedVersion,
            null,
            function () use ($confirmRecordedSourceWasWrong): ?WorkId {
                if (!$confirmRecordedSourceWasWrong) {
                    throw new ValidationException(
                        "Removing a Reading Round source requires explicit confirmation."
                    );
                }

                return null;
            },
            true
        );
    }

    /** @param callable(): ?WorkId $resolveSourceWork */
    private function correct(
        ReadingRoundId $id,
        ReadingRoundVersion $expectedVersion,
        ?ReadingSource $desiredSource,
        callable $resolveSourceWork,
        bool $unknown = false
    ): ReadingRound {
        $actorId = $this->authenticatedUser->requireUserId();

        return $this->transactions->run(function () use (
            $actorId,
            $id,
            $expectedVersion,
            $desiredSource,
            $resolveSourceWork,
            $unknown
        ): ReadingRound {
            $current = $this->rounds->findForUserForUpdate($id, $actorId);

            if ($current === null) {
                throw new ReadingRoundNotAvailable();
            }

            if (ReadingSource::same($current->source(), $desiredSource)) {
                return $current;
            }

            if (!$current->version()->equals($expectedVersion)) {
                throw new ReadingRoundStale($current);
            }

            $sourceWorkId = $resolveSourceWork();

            if (
                !$unknown
                && ($sourceWorkId === null
                    || !$sourceWorkId->equals($current->workId()))
            ) {
                throw new ReadingRoundSourceCorrectionUnavailable();
            }

            $replacement = $current->correctSource(
                $desiredSource,
                $this->clock->now()
            );

            if (!$this->rounds->replaceIfVersionMatches(
                $actorId,
                $replacement,
                $expectedVersion,
                $current->lifecycle()
            )) {
                throw new ReadingRoundStale(
                    $this->rounds->findForUserForUpdate($id, $actorId)
                        ?? $current
                );
            }

            return $replacement;
        });
    }
}
