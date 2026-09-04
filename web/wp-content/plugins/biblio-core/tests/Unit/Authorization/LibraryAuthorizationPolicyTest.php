<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Authorization;

use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\AdditionalPermissions;
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
    #[DataProvider("managementAuthorizationCases")]
    public function testManagementAuthorizationUsesExplicitPermissions(
        ManagementRole $role,
        UseAccess $useAccess,
        MembershipStatus $status,
        AdditionalPermissions $permissions,
        bool $canAddItem,
        bool $canManageClassification
    ): void {
        [$context, $assignment] = $this->membership(
            $useAccess,
            $status,
            $role,
            $permissions
        );
        $policy = new LibraryAuthorizationPolicy();

        self::assertSame(
            $canAddItem,
            $policy->canAddCatalogItem($context, $assignment)
        );
        self::assertSame(
            $canAddItem,
            $policy->canInitializeCatalogContextDuringItemAdd(
                $context,
                $assignment
            )
        );
        self::assertSame(
            $canManageClassification,
            $policy->canModifyLibraryCatalogContext($context, $assignment)
        );
        self::assertSame(
            $canManageClassification,
            $policy->canManageClassificationTerms($context, $assignment)
        );
    }

    public static function managementAuthorizationCases(): iterable
    {
        yield "active owner direct" => [
            ManagementRole::Owner,
            UseAccess::Direct,
            MembershipStatus::Active,
            AdditionalPermissions::none(),
            true,
            true,
        ];
        yield "inactive owner direct" => [
            ManagementRole::Owner,
            UseAccess::Direct,
            MembershipStatus::Inactive,
            AdditionalPermissions::fromValues(
                AdditionalPermissions::CATALOG_ITEM_ADD,
                AdditionalPermissions::CATALOG_CLASSIFICATION_MANAGE
            ),
            false,
            false,
        ];

        $permissionCases = [
            "none" => [AdditionalPermissions::none(), false, false],
            "item add" => [AdditionalPermissions::fromValues(
                AdditionalPermissions::CATALOG_ITEM_ADD
            ), true, false],
            "classification manage" => [AdditionalPermissions::fromValues(
                AdditionalPermissions::CATALOG_CLASSIFICATION_MANAGE
            ), false, true],
            "both" => [AdditionalPermissions::fromValues(
                AdditionalPermissions::CATALOG_ITEM_ADD,
                AdditionalPermissions::CATALOG_CLASSIFICATION_MANAGE
            ), true, true],
        ];

        foreach (UseAccess::cases() as $useAccess) {
            foreach ($permissionCases as $label => $permissionCase) {
                [$permissions, $canAdd, $canClassify] = $permissionCase;

                yield "active manager {$useAccess->value} {$label}" => [
                    ManagementRole::Manager,
                    $useAccess,
                    MembershipStatus::Active,
                    $permissions,
                    $canAdd,
                    $canClassify,
                ];
            }

            yield "inactive manager {$useAccess->value} with both" => [
                ManagementRole::Manager,
                $useAccess,
                MembershipStatus::Inactive,
                $permissionCases["both"][0],
                false,
                false,
            ];

            yield "active member {$useAccess->value} with both" => [
                ManagementRole::Member,
                $useAccess,
                MembershipStatus::Active,
                $permissionCases["both"][0],
                false,
                false,
            ];
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

    public function testContributionPublicationAndModerationAuthorization(): void
    {
        $policy = new LibraryAuthorizationPolicy();
        [$memberContext, $member] = $this->membership(UseAccess::ViewOnly);
        self::assertTrue($policy->canPublishContribution($memberContext, $member));
        self::assertFalse($policy->canModerateContribution($memberContext, $member));

        [$managerContext, $manager] = $this->membership(
            UseAccess::ViewOnly,
            MembershipStatus::Active,
            ManagementRole::Manager,
            AdditionalPermissions::fromValues(
                AdditionalPermissions::CONTRIBUTION_MODERATE
            )
        );
        self::assertTrue($policy->canModerateContribution($managerContext, $manager));

        [$inactiveContext, $inactiveManager] = $this->membership(
            UseAccess::Direct,
            MembershipStatus::Inactive,
            ManagementRole::Manager,
            AdditionalPermissions::fromValues(
                AdditionalPermissions::CONTRIBUTION_MODERATE
            )
        );
        self::assertFalse(
            $policy->canModerateContribution($inactiveContext, $inactiveManager)
        );

        [$ownerContext, $owner] = $this->membership(
            UseAccess::Direct,
            MembershipStatus::Active,
            ManagementRole::Owner
        );
        self::assertTrue($policy->canModerateContribution($ownerContext, $owner));
    }

    public function testCollectionManagementUsesOwnerOverrideAndCanonicalManagerPermission(): void
    {
        $policy = new LibraryAuthorizationPolicy();
        [$ownerContext, $owner] = $this->membership(
            UseAccess::Direct,
            MembershipStatus::Active,
            ManagementRole::Owner
        );
        self::assertTrue($policy->canManageCollections($ownerContext, $owner));

        [$managerContext, $manager] = $this->membership(
            UseAccess::ViewOnly,
            MembershipStatus::Active,
            ManagementRole::Manager,
            AdditionalPermissions::fromValues(AdditionalPermissions::COLLECTIONS_MANAGE)
        );
        self::assertTrue($policy->canManageCollections($managerContext, $manager));

        [$deniedContext, $denied] = $this->membership(
            UseAccess::Direct,
            MembershipStatus::Active,
            ManagementRole::Member,
            AdditionalPermissions::fromValues(AdditionalPermissions::COLLECTIONS_MANAGE)
        );
        self::assertFalse($policy->canManageCollections($deniedContext, $denied));
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
        ManagementRole $role = ManagementRole::Member,
        ?AdditionalPermissions $permissions = null
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
                    $status,
                    $permissions
                )
            )
        ];
    }
}
