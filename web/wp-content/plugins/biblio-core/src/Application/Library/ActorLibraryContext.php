<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Library;

use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryMembershipAssignment;

final readonly class ActorLibraryContext
{
    public function __construct(
        private Library $library,
        private LibraryMembershipAssignment $membership,
        private bool $designatedPersonal
    ) {
    }

    public function library(): Library
    {
        return $this->library;
    }

    public function membership(): LibraryMembershipAssignment
    {
        return $this->membership;
    }

    public function isDesignatedPersonal(): bool
    {
        return $this->designatedPersonal;
    }
}
