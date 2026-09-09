<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Identity;

use Biblio\Core\Application\Library\ProvisionPersonalPrivateLibraryService;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembershipRepository;
use Biblio\Core\Library\LibraryRepository;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\PersonalLibraryRepository;
use Biblio\Core\Library\UseAccess;

final readonly class PersonalMigrationTargetService
{
    public function __construct(
        private PlatformUserDirectory $users,
        private PersonalLibraryRepository $personalLibraries,
        private LibraryRepository $libraries,
        private LibraryMembershipRepository $memberships,
        private ProvisionPersonalPrivateLibraryService $provisioner,
        private PersonalMigrationTargetContentRepository $content
    ) {
    }

    public function bootstrap(UserId $targetUserId): PersonalMigrationTarget
    {
        $this->assertActiveUser($targetUserId);

        return $this->validate(
            $targetUserId,
            $this->provisioner->provision($targetUserId)
        );
    }

    public function validate(
        UserId $targetUserId,
        LibraryId $targetLibraryId
    ): PersonalMigrationTarget {
        $this->assertActiveUser($targetUserId);

        $designated = $this->personalLibraries->findForUser($targetUserId);

        if (
            $designated === null
            || !$designated->equals($targetLibraryId)
        ) {
            throw new PersonalMigrationTargetInvalid(
                "The target Library is not the user's designated personal Library."
            );
        }

        $library = $this->libraries->find($targetLibraryId);

        if ($library === null) {
            throw new PersonalMigrationTargetInvalid(
                "The target Library does not exist as an active private Library."
            );
        }

        $assignment = $this->memberships->findFor(
            $targetLibraryId,
            $targetUserId
        );
        $membership = $assignment?->membership();

        if (
            $membership === null
            || $membership->status() !== MembershipStatus::Active
            || $membership->managementRole() !== ManagementRole::Owner
            || $membership->useAccess() !== UseAccess::Direct
        ) {
            throw new PersonalMigrationTargetInvalid(
                "The target user is not the active Owner with direct access."
            );
        }

        return new PersonalMigrationTarget(
            $targetUserId,
            $targetLibraryId,
            $library->name(),
            $this->content->inspect($targetUserId, $targetLibraryId)
        );
    }

    private function assertActiveUser(UserId $targetUserId): void
    {
        if (!$this->users->isActive($targetUserId)) {
            throw new PersonalMigrationTargetInvalid(
                "The target user does not exist as an active platform user."
            );
        }
    }
}
