<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;

interface PersonalWorkReadingStatusSource
{
    /**
     * @param list<WorkId> $workIds
     * @return array<string, list<ReadingRound>>
     */
    public function findAllForUserAndWorks(UserId $userId, array $workIds): array;
}
