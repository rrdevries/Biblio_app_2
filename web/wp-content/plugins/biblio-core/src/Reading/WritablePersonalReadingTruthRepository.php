<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;

interface WritablePersonalReadingTruthRepository extends PersonalReadingTruthRepository
{
    public function findForUserAndWorkForUpdate(
        UserId $userId,
        WorkId $workId
    ): ?PersonalReadingTruth;

    public function add(PersonalReadingTruth $truth): void;

    public function replaceIfVersionMatches(
        PersonalReadingTruth $replacement,
        PersonalReadingTruthVersion $expectedVersion
    ): bool;
}
