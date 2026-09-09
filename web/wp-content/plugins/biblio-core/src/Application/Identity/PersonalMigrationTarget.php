<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Identity;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryName;

final readonly class PersonalMigrationTarget
{
    public function __construct(
        private UserId $userId,
        private LibraryId $libraryId,
        private LibraryName $libraryName,
        private PersonalMigrationTargetReadiness $readiness
    ) {
    }

    public function userId(): UserId { return $this->userId; }
    public function libraryId(): LibraryId { return $this->libraryId; }
    public function libraryName(): LibraryName { return $this->libraryName; }
    public function readiness(): PersonalMigrationTargetReadiness
    {
        return $this->readiness;
    }
}
