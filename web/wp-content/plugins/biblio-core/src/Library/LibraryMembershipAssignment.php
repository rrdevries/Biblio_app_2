<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

use Biblio\Core\Identity\UserId;

final readonly class LibraryMembershipAssignment
{
    public function __construct(
        private LibraryId $libraryId,
        private UserId $userId,
        private LibraryMembership $membership
    ) {
    }

    public function libraryId(): LibraryId
    {
        return $this->libraryId;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function membership(): LibraryMembership
    {
        return $this->membership;
    }
}
