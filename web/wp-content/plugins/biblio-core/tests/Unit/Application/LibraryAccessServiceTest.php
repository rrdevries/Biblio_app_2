<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\LibraryMembershipRepository;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use DomainException;
use PHPUnit\Framework\TestCase;

final class InMemoryLibraryMembershipRepository implements LibraryMembershipRepository
{
    private array $assignments = [];

    public function add(LibraryMembershipAssignment $assignment): void
    {
        $this->assignments[$this->key(
            $assignment->libraryId(),
            $assignment->userId()
        )] = $assignment;
    }

    public function findFor(
        LibraryId $libraryId,
        UserId $userId
    ): ?LibraryMembershipAssignment {
        return $this->assignments[$this->key($libraryId, $userId)] ?? null;
    }

    private function key(LibraryId $libraryId, UserId $userId): string
    {
        return $libraryId->value() . "|" . $userId->value();
    }
}

final class LibraryAccessServiceTest extends TestCase
{
    public function testCatalogManagementUsesRoleAndIgnoresUseAccess(): void
    {
        $userId = new UserId("user-1");
        $libraryId = new LibraryId("library-a");
        $repository = new InMemoryLibraryMembershipRepository();
        $repository->add($this->assignment(
            $libraryId,
            $userId,
            UseAccess::ViewOnly,
            ManagementRole::Manager
        ));
        $service = new LibraryAccessService(
            $repository,
            new LibraryAuthorizationPolicy()
        );

        self::assertTrue($service->canManageCatalog(
            new LibraryContext($libraryId, $userId)
        ));
    }

    public function testAccessForSameUserIsScopedByLibrary(): void
    {
        $userId = new UserId("user-1");
        $libraryA = new LibraryId("library-a");
        $libraryB = new LibraryId("library-b");
        $repository = new InMemoryLibraryMembershipRepository();

        $repository->add($this->assignment(
            $libraryA,
            $userId,
            UseAccess::Direct
        ));

        $repository->add($this->assignment(
            $libraryB,
            $userId,
            UseAccess::ViewOnly
        ));

        $service = new LibraryAccessService(
            $repository,
            new LibraryAuthorizationPolicy()
        );

        self::assertTrue($service->canUseItemDirectly(
            new LibraryContext($libraryA, $userId)
        ));

        self::assertFalse($service->canUseItemDirectly(
            new LibraryContext($libraryB, $userId)
        ));

        self::assertTrue($service->canViewCollection(
            new LibraryContext($libraryB, $userId)
        ));
    }

    public function testMissingMembershipDeniesAccess(): void
    {
        $service = new LibraryAccessService(
            new InMemoryLibraryMembershipRepository(),
            new LibraryAuthorizationPolicy()
        );

        $context = new LibraryContext(
            new LibraryId("library-a"),
            new UserId("user-1")
        );

        self::assertFalse($service->canViewCollection($context));
        self::assertFalse($service->canManageCatalog($context));
        self::assertFalse($service->canUseItemDirectly($context));
        self::assertFalse($service->canReceiveInternalLoan($context));
    }

    public function testForeignLibraryAssignmentFromRepositoryIsRejected(): void
    {
        $userId = new UserId("user-1");
        $foreignAssignment = $this->assignment(
            new LibraryId("library-b"),
            $userId,
            UseAccess::Direct
        );

        $repository = new class($foreignAssignment) implements LibraryMembershipRepository {
            public function __construct(
                private LibraryMembershipAssignment $assignment
            ) {
            }

            public function findFor(
                LibraryId $libraryId,
                UserId $userId
            ): ?LibraryMembershipAssignment {
                return $this->assignment;
            }
        };

        $service = new LibraryAccessService(
            $repository,
            new LibraryAuthorizationPolicy()
        );

        $this->expectException(DomainException::class);

        $service->canViewCollection(new LibraryContext(
            new LibraryId("library-a"),
            $userId
        ));
    }

    public function testForeignUserAssignmentFromRepositoryIsRejected(): void
    {
        $libraryId = new LibraryId("library-a");
        $foreignAssignment = $this->assignment(
            $libraryId,
            new UserId("user-2"),
            UseAccess::Direct
        );

        $repository = new class($foreignAssignment) implements LibraryMembershipRepository {
            public function __construct(
                private LibraryMembershipAssignment $assignment
            ) {
            }

            public function findFor(
                LibraryId $libraryId,
                UserId $userId
            ): ?LibraryMembershipAssignment {
                return $this->assignment;
            }
        };

        $service = new LibraryAccessService(
            $repository,
            new LibraryAuthorizationPolicy()
        );

        $this->expectException(DomainException::class);

        $service->canViewCollection(new LibraryContext(
            $libraryId,
            new UserId("user-1")
        ));
    }

    private function assignment(
        LibraryId $libraryId,
        UserId $userId,
        UseAccess $useAccess,
        ManagementRole $role = ManagementRole::Member
    ): LibraryMembershipAssignment {
        return new LibraryMembershipAssignment(
            $libraryId,
            $userId,
            new LibraryMembership(
                $role,
                $useAccess,
                MembershipStatus::Active
            )
        );
    }
}
