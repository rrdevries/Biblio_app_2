<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

use Biblio\Core\Exception\ValidationException;

final readonly class LibraryMembership
{
    private AdditionalPermissions $additionalPermissions;

    public function __construct(
        private ManagementRole $managementRole,
        private UseAccess $useAccess,
        private MembershipStatus $status,
        ?AdditionalPermissions $additionalPermissions = null
    ) {
        $this->additionalPermissions = $additionalPermissions
            ?? AdditionalPermissions::none();

        if (
            $this->managementRole === ManagementRole::Owner
            && $this->useAccess !== UseAccess::Direct
        ) {
            throw new ValidationException(
                "An owner must always have direct access."
            );
        }
    }

    public static function safeDefault(): self
    {
        return new self(
            ManagementRole::Member,
            UseAccess::ViewOnly,
            MembershipStatus::Active
        );
    }

    public static function owner(): self
    {
        return new self(
            ManagementRole::Owner,
            UseAccess::Direct,
            MembershipStatus::Active
        );
    }

    public function managementRole(): ManagementRole
    {
        return $this->managementRole;
    }

    public function useAccess(): UseAccess
    {
        return $this->useAccess;
    }

    public function status(): MembershipStatus
    {
        return $this->status;
    }

    public function additionalPermissions(): AdditionalPermissions
    {
        return $this->additionalPermissions;
    }
}
