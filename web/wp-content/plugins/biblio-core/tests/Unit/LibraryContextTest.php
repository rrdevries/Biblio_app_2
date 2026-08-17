<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use DomainException;
use PHPUnit\Framework\TestCase;

final class LibraryContextTest extends TestCase
{
    public function testSameUserMayHaveDifferentMembershipsInDifferentLibraries(): void
    {
        $userId = new UserId("user-1");
        $libraryA = new LibraryId("library-a");
        $libraryB = new LibraryId("library-b");

        $membershipA = new LibraryMembershipAssignment(
            $libraryA,
            $userId,
            new LibraryMembership(
                ManagementRole::Manager,
                UseAccess::Direct,
                MembershipStatus::Active
            )
        );

        $membershipB = new LibraryMembershipAssignment(
            $libraryB,
            $userId,
            new LibraryMembership(
                ManagementRole::Member,
                UseAccess::ViewOnly,
                MembershipStatus::Active
            )
        );

        self::assertSame(ManagementRole::Manager, $membershipA->membership()->managementRole());
        self::assertSame(UseAccess::Direct, $membershipA->membership()->useAccess());
        self::assertSame(ManagementRole::Member, $membershipB->membership()->managementRole());
        self::assertSame(UseAccess::ViewOnly, $membershipB->membership()->useAccess());
    }

    public function testContextAcceptsMembershipForItsUserAndLibrary(): void
    {
        $userId = new UserId("user-1");
        $libraryId = new LibraryId("library-a");
        $context = new LibraryContext($libraryId, $userId);

        $assignment = new LibraryMembershipAssignment(
            $libraryId,
            $userId,
            LibraryMembership::safeDefault()
        );

        $context->assertMembershipApplies($assignment);

        self::assertTrue(true);
    }

    public function testContextRejectsMembershipFromAnotherLibrary(): void
    {
        $userId = new UserId("user-1");
        $context = new LibraryContext(
            new LibraryId("library-a"),
            $userId
        );

        $assignment = new LibraryMembershipAssignment(
            new LibraryId("library-b"),
            $userId,
            LibraryMembership::safeDefault()
        );

        try {
            $context->assertMembershipApplies($assignment);
            self::fail("Foreign Library membership was accepted.");
        } catch (AuthorizationException $exception) {
            self::assertInstanceOf(DomainException::class, $exception);
            self::assertSame(
                FailureReason::AuthorizationDenied,
                $exception->reason()
            );
        }
    }

    public function testContextRejectsMembershipFromAnotherUser(): void
    {
        $libraryId = new LibraryId("library-a");
        $context = new LibraryContext(
            $libraryId,
            new UserId("user-1")
        );

        $assignment = new LibraryMembershipAssignment(
            $libraryId,
            new UserId("user-2"),
            LibraryMembership::safeDefault()
        );

        $this->expectException(DomainException::class);
        $context->assertMembershipApplies($assignment);
    }
}
