<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Catalog\WorkId;

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

    public function findForUserForUpdate(
        ReadingRoundId $readingRoundId,
        UserId $userId
    ): ?ReadingRound;

    /** @return list<ReadingRound> */
    public function findAllForUserAndWork(
        UserId $userId,
        WorkId $workId
    ): array;
}
