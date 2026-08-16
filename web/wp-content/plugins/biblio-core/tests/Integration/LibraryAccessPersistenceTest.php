<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;

final class LibraryAccessPersistenceTest extends PersistenceIntegrationTestCase
{
    public function testAccessServiceUsesStrictRealRepositoryScope(): void
    {
        $libraryA = new LibraryId("library-a");
        $libraryB = new LibraryId("library-b");
        $userX = new UserId("user-x");
        $userY = new UserId("user-y");
        $libraryRepository = new WpdbLibraryRepository(
            $this->database,
            $this->tableNames
        );
        $repository = new WpdbLibraryMembershipRepository(
            $this->database,
            $this->tableNames
        );
        $libraryRepository->add(Library::privateLibrary($libraryA));
        $libraryRepository->add(Library::privateLibrary($libraryB));
        $repository->add(new LibraryMembershipAssignment(
            $libraryA,
            $userX,
            new LibraryMembership(
                ManagementRole::Member,
                UseAccess::Direct,
                MembershipStatus::Active
            )
        ));
        $service = new LibraryAccessService(
            $repository,
            new LibraryAuthorizationPolicy()
        );

        self::assertTrue($service->canViewCollection(
            new LibraryContext($libraryA, $userX)
        ));
        self::assertTrue($service->canUseItemDirectly(
            new LibraryContext($libraryA, $userX)
        ));
        self::assertFalse($service->canViewCollection(
            new LibraryContext($libraryB, $userX)
        ));
        self::assertFalse($service->canViewCollection(
            new LibraryContext($libraryA, $userY)
        ));
    }

    public function testInactivePersistedMembershipCannotGrantAccess(): void
    {
        $libraryId = new LibraryId("library-a");
        $userId = new UserId("inactive-user");
        (new WpdbLibraryRepository(
            $this->database,
            $this->tableNames
        ))->add(Library::privateLibrary($libraryId));
        $repository = new WpdbLibraryMembershipRepository(
            $this->database,
            $this->tableNames
        );
        $repository->add(new LibraryMembershipAssignment(
            $libraryId,
            $userId,
            new LibraryMembership(
                ManagementRole::Manager,
                UseAccess::Borrow,
                MembershipStatus::Inactive
            )
        ));
        $service = new LibraryAccessService(
            $repository,
            new LibraryAuthorizationPolicy()
        );
        $context = new LibraryContext($libraryId, $userId);

        self::assertFalse($service->canViewCollection($context));
        self::assertFalse($service->canUseItemDirectly($context));
        self::assertFalse($service->canReceiveInternalLoan($context));
    }

    public function testManagerRoleAndBorrowAccessRemainIndependent(): void
    {
        $libraryId = new LibraryId("library-a");
        $userId = new UserId("manager");
        (new WpdbLibraryRepository(
            $this->database,
            $this->tableNames
        ))->add(Library::privateLibrary($libraryId));
        $repository = new WpdbLibraryMembershipRepository(
            $this->database,
            $this->tableNames
        );
        $repository->add(new LibraryMembershipAssignment(
            $libraryId,
            $userId,
            new LibraryMembership(
                ManagementRole::Manager,
                UseAccess::Borrow,
                MembershipStatus::Active
            )
        ));
        $service = new LibraryAccessService(
            $repository,
            new LibraryAuthorizationPolicy()
        );
        $context = new LibraryContext($libraryId, $userId);

        self::assertTrue($service->canViewCollection($context));
        self::assertFalse($service->canUseItemDirectly($context));
        self::assertTrue($service->canReceiveInternalLoan($context));
    }
}
