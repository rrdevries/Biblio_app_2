<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Authorization;

use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LibraryAuthorizationPolicyTest extends TestCase
{
    #[DataProvider("catalogManagementCases")]
    public function testCatalogManagementDependsOnlyOnActiveManagementRole(
        ManagementRole $role,
        UseAccess $useAccess,
        MembershipStatus $status,
        bool $expected
    ): void {
        [$context, $assignment] = $this->membership(
            $useAccess,
            $status,
            $role
        );

        self::assertSame(
            $expected,
            (new LibraryAuthorizationPolicy())->canManageCatalog(
                $context,
                $assignment
            )
        );
    }

    public static function catalogManagementCases(): iterable
    {
        yield "active owner direct" => [
            ManagementRole::Owner,
            UseAccess::Direct,
            MembershipStatus::Active,
            true,
        ];
        yield "inactive owner direct" => [
            ManagementRole::Owner,
            UseAccess::Direct,
            MembershipStatus::Inactive,
            false,
        ];

        foreach ([ManagementRole::Manager, ManagementRole::Member] as $role) {
            foreach (UseAccess::cases() as $useAccess) {
                foreach (MembershipStatus::cases() as $status) {
                    yield implode(" ", [
                        $status->value,
                        $role->value,
                        $useAccess->value,
                    ]) => [
                        $role,
                        $useAccess,
                        $status,
                        $role === ManagementRole::Manager
                            && $status === MembershipStatus::Active,
                    ];
                }
            }
        }
    }

    public function testViewOnlyMayViewButCannotUseOrBorrow(): void
    {
        [$context, $assignment] = $this->membership(UseAccess::ViewOnly);
        $policy = new LibraryAuthorizationPolicy();

        self::assertTrue($policy->canViewCollection($context, $assignment));
        self::assertFalse($policy->canUseItemDirectly($context, $assignment));
        self::assertFalse($policy->canReceiveInternalLoan($context, $assignment));
    }

    public function testBorrowMayViewAndBorrowButCannotUseDirectly(): void
    {
        [$context, $assignment] = $this->membership(UseAccess::Borrow);
        $policy = new LibraryAuthorizationPolicy();

        self::assertTrue($policy->canViewCollection($context, $assignment));
        self::assertFalse($policy->canUseItemDirectly($context, $assignment));
        self::assertTrue($policy->canReceiveInternalLoan($context, $assignment));
    }

    public function testDirectAccessMayViewUseAndReceiveLoan(): void
    {
        [$context, $assignment] = $this->membership(UseAccess::Direct);
        $policy = new LibraryAuthorizationPolicy();

        self::assertTrue($policy->canViewCollection($context, $assignment));
        self::assertTrue($policy->canUseItemDirectly($context, $assignment));
        self::assertTrue($policy->canReceiveInternalLoan($context, $assignment));
    }

    public function testInactiveMembershipHasNoNormalLibraryAccess(): void
    {
        [$context, $assignment] = $this->membership(
            UseAccess::Direct,
            MembershipStatus::Inactive
        );
        $policy = new LibraryAuthorizationPolicy();

        self::assertFalse($policy->canViewCollection($context, $assignment));
        self::assertFalse($policy->canUseItemDirectly($context, $assignment));
        self::assertFalse($policy->canReceiveInternalLoan($context, $assignment));
    }

    public function testPolicyRejectsAssignmentFromAnotherLibrary(): void
    {
        $userId = new UserId("user-1");
        $context = new LibraryContext(new LibraryId("library-a"), $userId);
        $assignment = new LibraryMembershipAssignment(
            new LibraryId("library-b"),
            $userId,
            LibraryMembership::safeDefault()
        );
        $policy = new LibraryAuthorizationPolicy();

        $this->expectException(DomainException::class);
        $policy->canViewCollection($context, $assignment);
    }

    private function membership(
        UseAccess $useAccess,
        MembershipStatus $status = MembershipStatus::Active,
        ManagementRole $role = ManagementRole::Member
    ): array {
        $userId = new UserId("user-1");
        $libraryId = new LibraryId("library-a");

        return [
            new LibraryContext($libraryId, $userId),
            new LibraryMembershipAssignment(
                $libraryId,
                $userId,
                new LibraryMembership(
                    $role,
                    $useAccess,
                    $status
                )
            )
        ];
    }
}
