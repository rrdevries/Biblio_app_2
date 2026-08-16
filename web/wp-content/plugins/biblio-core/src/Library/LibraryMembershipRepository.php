<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

use Biblio\Core\Identity\UserId;

interface LibraryMembershipRepository
{
    public function findFor(
        LibraryId $libraryId,
        UserId $userId
    ): ?LibraryMembershipAssignment;
}
