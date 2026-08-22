<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Catalog\WorkRepository;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Reading\ReadingPeriod;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundClock;
use Biblio\Core\Reading\ReadingRoundId;

final readonly class RegisterHistoricalReadingRoundService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WorkRepository $works,
        private ReadingRoundCreation $creation,
        private ReadingRoundClock $clock,
        private TransactionManager $transactions
    ) {
    }

    public function register(
        WorkId $workId,
        ReadingPeriod $period
    ): ReadingRound {
        $actorId = $this->authenticatedUser->requireUserId();

        return $this->transactions->run(function () use (
            $actorId,
            $workId,
            $period
        ): ReadingRound {
            if ($this->works->find($workId) === null) {
                throw new ValidationException("Work does not exist.");
            }

            if ($period->finishedOn() === null) {
                throw new ValidationException(
                    "Historical Reading Round requires a finish date."
                );
            }

            return $this->creation->create(
                $actorId,
                fn (ReadingRoundId $id): ReadingRound => ReadingRound::historical(
                    $id,
                    $actorId,
                    $workId,
                    $period,
                    $this->clock->now()
                )
            );
        });
    }
}
