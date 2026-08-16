<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Library;

use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\LibraryRepository;
use Biblio\Core\Library\WritableLibraryMembershipRepository;

final readonly class CreateLibraryService
{
    public function __construct(
        private LibraryRepository $libraryRepository,
        private WritableLibraryMembershipRepository $membershipRepository,
        private TransactionManager $transactionManager
    ) {
    }

    public function create(Library $library, UserId $ownerId): void
    {
        $ownerAssignment = new LibraryMembershipAssignment(
            $library->id(),
            $ownerId,
            LibraryMembership::owner()
        );

        $this->transactionManager->run(function () use (
            $library,
            $ownerAssignment
        ): void {
            $this->libraryRepository->add($library);
            $this->membershipRepository->add($ownerAssignment);
        });
    }
}
