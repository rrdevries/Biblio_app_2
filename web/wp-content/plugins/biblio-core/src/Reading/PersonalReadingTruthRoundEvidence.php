<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;

interface PersonalReadingTruthRoundEvidence
{
    public function hasCompletedForUserAndWorkForUpdate(
        UserId $userId,
        WorkId $workId
    ): bool;
}
