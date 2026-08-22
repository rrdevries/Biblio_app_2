<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Reading\ReadingDate;
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

final readonly class ReadingRoundEnd
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WritableReadingRoundRepository $rounds,
        private ReadingRoundClock $clock,
        private TransactionManager $transactions
    ) {
    }

    public function completed(
        ReadingRoundId $id,
        ReadingRoundVersion $expectedVersion,
        ReadingDate $finishedOn
    ): ReadingRound {
        return $this->end(
            $id,
            $expectedVersion,
            $finishedOn,
            ReadingRoundOutcome::Completed
        );
    }

    public function stopped(
        ReadingRoundId $id,
        ReadingRoundVersion $expectedVersion,
        ReadingDate $finishedOn
    ): ReadingRound {
        return $this->end(
            $id,
            $expectedVersion,
            $finishedOn,
            ReadingRoundOutcome::Stopped
        );
    }

    private function end(
        ReadingRoundId $id,
        ReadingRoundVersion $expectedVersion,
        ReadingDate $finishedOn,
        ReadingRoundOutcome $outcome
    ): ReadingRound {
        $actorId = $this->authenticatedUser->requireUserId();

        return $this->transactions->run(function () use (
            $actorId,
            $id,
            $expectedVersion,
            $finishedOn,
            $outcome
        ): ReadingRound {
            $current = $this->rounds->findForUserForUpdate($id, $actorId);

            if ($current === null) {
                throw new ReadingRoundNotAvailable();
            }

            $desiredPeriod = ReadingPeriod::ended(
                $current->period()->startedOn(),
                $finishedOn
            );

            if ($current->hasEndedContent($outcome, $desiredPeriod)) {
                return $current;
            }

            if ($current->lifecycle() === ReadingRoundLifecycle::Ended) {
                throw new ValidationException(
                    "An ended Reading Round can only change through correction."
                );
            }

            if (!$current->version()->equals($expectedVersion)) {
                throw new ReadingRoundStale($current);
            }

            $replacement = $current->end(
                $outcome,
                $finishedOn,
                $this->clock->now()
            );

            if (!$this->rounds->replaceIfVersionMatches(
                $actorId,
                $replacement,
                $expectedVersion,
                ReadingRoundLifecycle::Active
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
