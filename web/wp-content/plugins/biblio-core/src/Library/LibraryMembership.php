<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

use InvalidArgumentException;

final readonly class LibraryMembership
{
    public function __construct(
        private ManagementRole $managementRole,
        private UseAccess $useAccess,
        private MembershipStatus $status
    ) {
        if (
            $this->managementRole === ManagementRole::Owner
            && $this->useAccess !== UseAccess::Direct
        ) {
            throw new InvalidArgumentException(
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
}
