<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Identity\UserId;

interface ReadingRoundRepository
{
    public function findForUser(
        ReadingRoundId $readingRoundId,
        UserId $userId
    ): ?ReadingRound;

    public function findActiveForUserAndSource(
        UserId $userId,
        ReadingSource $source
    ): ?ReadingRound;
}
