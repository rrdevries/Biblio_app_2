<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;

interface PersonalReadingTruthRepository
{
    public function findForUserAndWork(
        UserId $userId,
        WorkId $workId
    ): ?PersonalReadingTruth;

    /**
     * @param list<WorkId> $workIds
     * @return array<string, PersonalReadingTruth>
     */
    public function findAllForUserAndWorks(
        UserId $userId,
        array $workIds
    ): array;
}
