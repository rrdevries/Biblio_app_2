<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

interface WritableLibraryMembershipRepository extends LibraryMembershipRepository
{
    public function add(LibraryMembershipAssignment $assignment): void;
}
