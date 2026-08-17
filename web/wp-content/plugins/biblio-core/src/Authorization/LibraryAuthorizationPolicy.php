<?php

declare(strict_types=1);

namespace Biblio\Core\Authorization;

use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;

final class LibraryAuthorizationPolicy
{
    public function canManageCatalog(
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

    public function canViewCollection(
        LibraryContext $context,
        LibraryMembershipAssignment $assignment
    ): bool {
        $context->assertMembershipApplies($assignment);

        return $assignment->membership()->status() === MembershipStatus::Active;
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
}
