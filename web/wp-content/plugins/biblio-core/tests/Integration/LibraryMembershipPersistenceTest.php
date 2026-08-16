<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Library\AdditionalPermissions;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;

final class LibraryMembershipPersistenceTest extends
    PersistenceIntegrationTestCase
{
    public function testCanonicalMembershipDimensionsRoundTripIndependently(): void
    {
        $libraryId = new LibraryId("library-a");
        $this->libraryRepository()->add(Library::privateLibrary($libraryId));
        $repository = $this->membershipRepository();

        $assignments = [
            new LibraryMembershipAssignment(
                $libraryId,
                new UserId("owner"),
                LibraryMembership::owner()
            ),
            new LibraryMembershipAssignment(
                $libraryId,
                new UserId("manager"),
                new LibraryMembership(
                    ManagementRole::Manager,
                    UseAccess::Borrow,
                    MembershipStatus::Active,
                    AdditionalPermissions::fromValues(
                        "collections",
                        "lending"
                    )
                )
            ),
            new LibraryMembershipAssignment(
                $libraryId,
                new UserId("member"),
                LibraryMembership::safeDefault()
            ),
            new LibraryMembershipAssignment(
                $libraryId,
                new UserId("inactive-member"),
                new LibraryMembership(
                    ManagementRole::Member,
                    UseAccess::Direct,
                    MembershipStatus::Inactive
                )
            ),
        ];

        foreach ($assignments as $assignment) {
            $repository->add($assignment);
        }

        $this->assertRoundTrip($assignments[0], []);
        $this->assertRoundTrip(
            $assignments[1],
            ["collections", "lending"]
        );
        $this->assertRoundTrip($assignments[2], []);
        $this->assertRoundTrip($assignments[3], []);
    }

    public function testMembershipLookupNeverFallsBackAcrossScope(): void
    {
        $libraryA = new LibraryId("library-a");
        $libraryB = new LibraryId("library-b");
        $userX = new UserId("user-x");
        $userY = new UserId("user-y");
        $this->libraryRepository()->add(Library::privateLibrary($libraryA));
        $this->libraryRepository()->add(Library::privateLibrary($libraryB));
        $repository = $this->membershipRepository();
        $repository->add(new LibraryMembershipAssignment(
            $libraryA,
            $userX,
            LibraryMembership::safeDefault()
        ));

        self::assertNotNull($repository->findFor($libraryA, $userX));
        self::assertNull($repository->findFor($libraryB, $userX));
        self::assertNull($repository->findFor($libraryA, $userY));
        self::assertNull($repository->findFor($libraryB, $userY));
    }

    private function assertRoundTrip(
        LibraryMembershipAssignment $expected,
        array $expectedPermissions
    ): void {
        $stored = $this->membershipRepository()->findFor(
            $expected->libraryId(),
            $expected->userId()
        );

        self::assertNotNull($stored);
        self::assertSame(
            $expected->libraryId()->value(),
            $stored->libraryId()->value()
        );
        self::assertSame(
            $expected->userId()->value(),
            $stored->userId()->value()
        );
        self::assertSame(
            $expected->membership()->managementRole(),
            $stored->membership()->managementRole()
        );
        self::assertSame(
            $expected->membership()->useAccess(),
            $stored->membership()->useAccess()
        );
        self::assertSame(
            $expected->membership()->status(),
            $stored->membership()->status()
        );
        self::assertSame(
            $expectedPermissions,
            $stored->membership()->additionalPermissions()->values()
        );
    }

    private function libraryRepository(): WpdbLibraryRepository
    {
        return new WpdbLibraryRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function membershipRepository(): WpdbLibraryMembershipRepository
    {
        return new WpdbLibraryMembershipRepository(
            $this->database,
            $this->tableNames
        );
    }
}
