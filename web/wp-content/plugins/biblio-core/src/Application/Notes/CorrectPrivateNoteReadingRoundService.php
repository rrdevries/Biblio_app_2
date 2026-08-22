<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Notes;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Notes\PrivateNote;
use Biblio\Core\Notes\PrivateNoteClock;
use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Notes\PrivateNoteNotAvailable;
use Biblio\Core\Notes\PrivateNoteReadingRoundUnavailable;
use Biblio\Core\Notes\PrivateNoteStale;
use Biblio\Core\Notes\PrivateNoteVersion;
use Biblio\Core\Notes\WritablePrivateNoteRepository;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundRepository;

final readonly class CorrectPrivateNoteReadingRoundService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WritablePrivateNoteRepository $notes,
        private ReadingRoundRepository $rounds,
        private PrivateNoteClock $clock,
        private TransactionManager $transactions
    ) {
    }

    public function correct(
        PrivateNoteId $id,
        PrivateNoteVersion $expectedVersion,
        ?ReadingRoundId $readingRoundId
    ): PrivateNote {
        $actorId = $this->authenticatedUser->requireUserId();

        return $this->transactions->run(function () use (
            $actorId,
            $id,
            $expectedVersion,
            $readingRoundId
        ): PrivateNote {
            $current = $this->notes->findForUserForUpdate($id, $actorId);

            if ($current === null) {
                throw new PrivateNoteNotAvailable();
            }

            if ($current->hasReadingRound($readingRoundId)) {
                return $current;
            }

            if (!$current->version()->equals($expectedVersion)) {
                throw new PrivateNoteStale($current);
            }

            if ($readingRoundId !== null) {
                $round = $this->rounds->findForUserForUpdate(
                    $readingRoundId,
                    $actorId
                );

                if ($round === null || !$round->workId()->equals($current->workId())) {
                    throw new PrivateNoteReadingRoundUnavailable();
                }
            }

            $replacement = $current->correctReadingRound(
                $readingRoundId,
                $this->clock->now()
            );

            if (!$this->notes->replaceIfVersionMatches(
                $actorId,
                $replacement,
                $expectedVersion
            )) {
                throw new PrivateNoteStale(
                    $this->notes->findForUserForUpdate($id, $actorId) ?? $current
                );
            }

            return $replacement;
        });
    }
}
