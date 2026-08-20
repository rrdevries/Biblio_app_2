<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Library;

use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Catalog\Classification\ClassificationSeedEvolution;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\WritableLibraryMembershipRepository;
use Biblio\Core\Library\WritableLibraryRepository;

final readonly class CreateLibraryService
{
    public function __construct(
        private WritableLibraryRepository $libraryRepository,
        private WritableLibraryMembershipRepository $membershipRepository,
        private ClassificationSeedEvolution $seedEvolution,
        private TransactionManager $transactionManager
    ) {
    }

    public function create(Library $library, UserId $ownerId): void
    {
        $this->createAndThen(
            $library,
            $ownerId,
            static fn (): null => null
        );
    }

    public function createAndThen(
        Library $library,
        UserId $ownerId,
        callable $continuation
    ): mixed
    {
        $ownerAssignment = new LibraryMembershipAssignment(
            $library->id(),
            $ownerId,
            LibraryMembership::owner()
        );

        return $this->transactionManager->run(function () use (
            $library,
            $ownerAssignment,
            $continuation
        ): mixed {
            $this->libraryRepository->add($library);
            $this->membershipRepository->add($ownerAssignment);
            $this->seedEvolution->evolve($library->id());

            return $continuation();
        });
    }
}
