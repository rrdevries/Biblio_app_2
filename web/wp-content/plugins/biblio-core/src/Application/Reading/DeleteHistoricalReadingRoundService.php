<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Reading\ReadingRoundDeletionNotAllowed;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundNotAvailable;
use Biblio\Core\Reading\ReadingRoundProvenance;
use Biblio\Core\Reading\ReadingRoundStale;
use Biblio\Core\Reading\ReadingRoundVersion;
use Biblio\Core\Reading\WritableReadingRoundRepository;

final readonly class DeleteHistoricalReadingRoundService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WritableReadingRoundRepository $rounds,
        private TransactionManager $transactions
    ) {
    }

    public function delete(
        ReadingRoundId $id,
        ReadingRoundVersion $expectedVersion
    ): void {
        $actorId = $this->authenticatedUser->requireUserId();

        $this->transactions->run(function () use (
            $actorId,
            $id,
            $expectedVersion
        ): void {
            $current = $this->rounds->findForUserForUpdate($id, $actorId);

            if ($current === null) {
                throw new ReadingRoundNotAvailable();
            }

            if ($current->provenance() !== ReadingRoundProvenance::HistoricalManual) {
                throw new ReadingRoundDeletionNotAllowed();
            }

            if (!$current->version()->equals($expectedVersion)) {
                throw new ReadingRoundStale($current);
            }

            if (!$this->rounds->deleteHistoricalIfVersionMatches(
                $actorId,
                $id,
                $expectedVersion
            )) {
                throw new ReadingRoundStale($current);
            }
        });
    }
}
