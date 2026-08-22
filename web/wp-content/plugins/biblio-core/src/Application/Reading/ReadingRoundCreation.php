<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundIdCollision;
use Biblio\Core\Reading\ReadingRoundIdCollisionExhausted;
use Biblio\Core\Reading\ReadingRoundIdGenerator;
use Biblio\Core\Reading\WritableReadingRoundRepository;

final readonly class ReadingRoundCreation
{
    private const MAX_COLLISION_RETRIES = 3;

    public function __construct(
        private ReadingRoundIdGenerator $ids,
        private WritableReadingRoundRepository $rounds
    ) {
    }

    /** @param callable(ReadingRoundId): ReadingRound $factory */
    public function create(UserId $actorId, callable $factory): ReadingRound
    {
        $retries = 0;

        while (true) {
            $round = $factory($this->ids->next());

            try {
                $this->rounds->addForUser($actorId, $round);

                return $round;
            } catch (ReadingRoundIdCollision $collision) {
                if ($retries >= self::MAX_COLLISION_RETRIES) {
                    throw new ReadingRoundIdCollisionExhausted($collision);
                }

                $retries++;
            }
        }
    }
}
