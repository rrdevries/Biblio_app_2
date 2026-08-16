<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

use Biblio\Core\Identity\UserId;
use DomainException;

final readonly class LibraryContext
{
    public function __construct(
        private LibraryId $libraryId,
        private UserId $userId
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

    public function assertMembershipApplies(
        LibraryMembershipAssignment $assignment
    ): void {
        if (!$this->libraryId->equals($assignment->libraryId())) {
            throw new DomainException(
                "Membership belongs to another library."
            );
        }

        if (!$this->userId->equals($assignment->userId())) {
            throw new DomainException(
                "Membership belongs to another user."
            );
        }
    }
}
