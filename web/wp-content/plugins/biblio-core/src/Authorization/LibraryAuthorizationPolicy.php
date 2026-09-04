<?php

declare(strict_types=1);

namespace Biblio\Core\Authorization;

use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\AdditionalPermissions;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;

final class LibraryAuthorizationPolicy
{
    public function canAddCatalogItem(
        LibraryContext $context,
        LibraryMembershipAssignment $assignment
    ): bool {
        return $this->canUseManagementPermission(
            $context,
            $assignment,
            AdditionalPermissions::CATALOG_ITEM_ADD
        );
    }

    public function canManageCatalogItems(
        LibraryContext $context,
        LibraryMembershipAssignment $assignment
    ): bool {
        $context->assertMembershipApplies($assignment);
        $membership = $assignment->membership();

        return $membership->status() === MembershipStatus::Active
            && in_array($membership->managementRole(), [
                ManagementRole::Owner,
                ManagementRole::Manager,
            ], true);
    }

    public function canInitializeCatalogContextDuringItemAdd(
        LibraryContext $context,
        LibraryMembershipAssignment $assignment
    ): bool {
        return $this->canAddCatalogItem($context, $assignment);
    }

    public function canModifyLibraryCatalogContext(
        LibraryContext $context,
        LibraryMembershipAssignment $assignment
    ): bool {
        return $this->canUseManagementPermission(
            $context,
            $assignment,
            AdditionalPermissions::CATALOG_CLASSIFICATION_MANAGE
        );
    }

    public function canManageClassificationTerms(
        LibraryContext $context,
        LibraryMembershipAssignment $assignment
    ): bool {
        return $this->canUseManagementPermission(
            $context,
            $assignment,
            AdditionalPermissions::CATALOG_CLASSIFICATION_MANAGE
        );
    }

    public function canViewCollection(
        LibraryContext $context,
        LibraryMembershipAssignment $assignment
    ): bool {
        $context->assertMembershipApplies($assignment);

        return $assignment->membership()->status() === MembershipStatus::Active;
    }

    public function canPublishContribution(
        LibraryContext $context,
        LibraryMembershipAssignment $assignment
    ): bool {
        return $this->canViewCollection($context, $assignment);
    }

    public function canModerateContribution(
        LibraryContext $context,
        LibraryMembershipAssignment $assignment
    ): bool {
        return $this->canUseManagementPermission(
            $context,
            $assignment,
            AdditionalPermissions::CONTRIBUTION_MODERATE
        );
    }

    public function canUseItemDirectly(
        LibraryContext $context,
        LibraryMembershipAssignment $assignment
    ): bool {
        $context->assertMembershipApplies($assignment);

        $membership = $assignment->membership();

        return $membership->status() === MembershipStatus::Active
            && $membership->useAccess() === UseAccess::Direct;
    }

    public function canReceiveInternalLoan(
        LibraryContext $context,
        LibraryMembershipAssignment $assignment
    ): bool {
        $context->assertMembershipApplies($assignment);

        $membership = $assignment->membership();

        if ($membership->status() !== MembershipStatus::Active) {
            return false;
        }

        return match ($membership->useAccess()) {
            UseAccess::Direct, UseAccess::Borrow => true,
            UseAccess::ViewOnly => false,
        };
    }

    private function canUseManagementPermission(
        LibraryContext $context,
        LibraryMembershipAssignment $assignment,
        string $permission
    ): bool {
        $context->assertMembershipApplies($assignment);
        $membership = $assignment->membership();

        if ($membership->status() !== MembershipStatus::Active) {
            return false;
        }

        return match ($membership->managementRole()) {
            ManagementRole::Owner => true,
            ManagementRole::Manager => $membership
                ->additionalPermissions()
                ->contains($permission),
            ManagementRole::Member => false,
        };
    }
}
