<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Identity;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;

interface MigrationTargetValidator
{
    public function validate(
        UserId $targetUserId,
        LibraryId $targetLibraryId
    ): PersonalMigrationTarget;
}
