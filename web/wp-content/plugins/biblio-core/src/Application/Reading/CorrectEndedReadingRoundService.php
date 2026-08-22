<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Reading\ReadingPeriod;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundClock;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundLifecycle;
use Biblio\Core\Reading\ReadingRoundNotAvailable;
use Biblio\Core\Reading\ReadingRoundOutcome;
use Biblio\Core\Reading\ReadingRoundStale;
use Biblio\Core\Reading\ReadingRoundVersion;
use Biblio\Core\Reading\WritableReadingRoundRepository;

final readonly class CorrectEndedReadingRoundService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WritableReadingRoundRepository $rounds,
        private ReadingRoundClock $clock,
        private TransactionManager $transactions
    ) {
    }

    public function correct(
        ReadingRoundId $id,
        ReadingRoundVersion $expectedVersion,
        ReadingRoundOutcome $outcome,
        ReadingPeriod $period
    ): ReadingRound {
        $actorId = $this->authenticatedUser->requireUserId();

        return $this->transactions->run(function () use (
            $actorId,
            $id,
            $expectedVersion,
            $outcome,
            $period
        ): ReadingRound {
            $current = $this->rounds->findForUserForUpdate($id, $actorId);

            if ($current === null) {
                throw new ReadingRoundNotAvailable();
            }

            if ($current->hasEndedContent($outcome, $period)) {
                return $current;
            }

            if (!$current->version()->equals($expectedVersion)) {
                throw new ReadingRoundStale($current);
            }

            $replacement = $current->correctEnded(
                $outcome,
                $period,
                $this->clock->now()
            );

            if (!$this->rounds->replaceIfVersionMatches(
                $actorId,
                $replacement,
                $expectedVersion,
                ReadingRoundLifecycle::Ended
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
