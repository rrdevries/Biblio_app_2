<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;

interface PersonalWorkReadingMutationLock
{
    public function acquire(UserId $userId, WorkId $workId): void;
}
