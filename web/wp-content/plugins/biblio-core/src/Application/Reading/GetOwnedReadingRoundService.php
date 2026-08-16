<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundRepository;

final readonly class GetOwnedReadingRoundService
{
    public function __construct(
        private ReadingRoundRepository $readingRoundRepository
    ) {
    }

    public function get(
        UserId $authenticatedUserId,
        ReadingRoundId $readingRoundId
    ): ?ReadingRound {
        $readingRound = $this->readingRoundRepository->findForUser(
            $readingRoundId,
            $authenticatedUserId
        );

        if (
            $readingRound === null
            || !$authenticatedUserId->equals($readingRound->userId())
        ) {
            return null;
        }

        return $readingRound;
    }
}
