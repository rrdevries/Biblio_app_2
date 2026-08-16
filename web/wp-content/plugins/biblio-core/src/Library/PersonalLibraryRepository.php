<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

use Biblio\Core\Identity\UserId;

interface PersonalLibraryRepository
{
    public function findForUser(UserId $userId): ?LibraryId;

    /**
     * @throws PersonalLibraryDesignationConflict
     */
    public function designate(UserId $userId, LibraryId $libraryId): void;
}
