<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Library\AdditionalPermissions;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LibraryMembershipTest extends TestCase
{
    public function testSafeDefaultIsActiveMemberWithViewOnlyAccess(): void
    {
        $membership = LibraryMembership::safeDefault();

        self::assertSame(ManagementRole::Member, $membership->managementRole());
        self::assertSame(UseAccess::ViewOnly, $membership->useAccess());
        self::assertSame(MembershipStatus::Active, $membership->status());
    }

    public function testOwnerAlwaysHasDirectAccess(): void
    {
        $membership = LibraryMembership::owner();

        self::assertSame(ManagementRole::Owner, $membership->managementRole());
        self::assertSame(UseAccess::Direct, $membership->useAccess());
        self::assertSame(MembershipStatus::Active, $membership->status());
    }

    public function testOwnerWithBorrowAccessIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LibraryMembership(
            ManagementRole::Owner,
            UseAccess::Borrow,
            MembershipStatus::Active
        );
    }

    public function testOwnerWithViewOnlyAccessIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LibraryMembership(
            ManagementRole::Owner,
            UseAccess::ViewOnly,
            MembershipStatus::Active
        );
    }

    public function testManagerMayHaveBorrowAccess(): void
    {
        $membership = new LibraryMembership(
            ManagementRole::Manager,
            UseAccess::Borrow,
            MembershipStatus::Active
        );

        self::assertSame(UseAccess::Borrow, $membership->useAccess());
    }

    public function testMemberMayHaveDirectAccess(): void
    {
        $membership = new LibraryMembership(
            ManagementRole::Member,
            UseAccess::Direct,
            MembershipStatus::Active
        );

        self::assertSame(UseAccess::Direct, $membership->useAccess());
    }

    public function testAdditionalPermissionsRemainASeparateDimension(): void
    {
        $membership = new LibraryMembership(
            ManagementRole::Manager,
            UseAccess::Borrow,
            MembershipStatus::Active,
            AdditionalPermissions::fromValues("lending")
        );

        self::assertSame(ManagementRole::Manager, $membership->managementRole());
        self::assertSame(UseAccess::Borrow, $membership->useAccess());
        self::assertSame(
            ["lending"],
            $membership->additionalPermissions()->values()
        );
    }
}
