<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Identity\UserId;

interface WritableReadingRoundRepository extends ReadingRoundRepository
{
    public function addForUser(
        UserId $authenticatedUserId,
        ReadingRound $readingRound
    ): void;
}
