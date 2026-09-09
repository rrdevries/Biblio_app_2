<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Identity;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;

interface PersonalMigrationTargetContentRepository
{
    public function inspect(
        UserId $userId,
        LibraryId $libraryId
    ): PersonalMigrationTargetReadiness;
}
