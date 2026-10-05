<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Accounts;

use Biblio\Core\Accounts\{AccountDirectory,AccountPreparation,AccountPreparationRepository};
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\ProvisionPersonalPrivateLibraryService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\{LibraryId,LibraryName,LibraryRepository,LibraryMembershipRepository,PersonalLibraryRepository,ManagementRole,MembershipStatus,UseAccess,LibraryType};

final readonly class AccountPreparationService
{
    public function __construct(
        private AuthenticatedUser $actor,
        private AccountDirectory $directory,
        private AccountPreparationRepository $preparations,
        private PersonalLibraryRepository $designations,
        private LibraryRepository $libraries,
        private LibraryMembershipRepository $memberships,
        private ProvisionPersonalPrivateLibraryService $provisioner,
        private TransactionManager $transactions
    ) {}

    public function assertManagement(): void
    {
        if (!$this->directory->canManageAccounts($this->actor->requireUserId())) {
            throw new AuthorizationException("Platform account management permission is required.");
        }
    }

    public function prepare(UserId $target): LibraryId
    {
        $this->assertManagement();
        $this->assertActive($target);
        if (!$this->directory->isRequested($target)) {
            throw new AuthorizationException("Account preparation requires explicit Biblio creation intent.");
        }
        return $this->preparations->exclusively($target, function () use ($target): LibraryId {
            $state = $this->preparations->find($target);
            if ($state === null) {
                $state = new AccountPreparation($target);
                $this->preparations->save($state);
            }
            if ($state->libraryId !== null) {
                $this->assertPersonalOwner($target, $state->libraryId);
                return $state->libraryId;
            }
            $id = $this->designations->findForUser($target);
            if ($id === null && $this->preparations->ownedLibraries($target) !== []) {
                throw new AuthorizationException("Existing ownership requires explicit operator diagnosis.");
            }
            $id ??= $this->provisioner->provision($target);
            $this->assertPersonalOwner($target, $id);
            $this->preparations->save(new AccountPreparation($target, $id));
            return $id;
        });
    }

    /** Targeted existing-account repair; never invoked from login. */
    public function repair(UserId $target, ?LibraryId $exactLibrary = null): LibraryId
    {
        $this->assertManagement();
        $this->assertActive($target);
        if ($this->directory->isRequested($target)) {
            throw new AuthorizationException("Resume new-account preparation instead of bypassing its naming step.");
        }
        return $this->preparations->exclusively($target, function () use ($target, $exactLibrary): LibraryId {
            $id = $this->designations->findForUser($target);
            if ($id !== null) {
                if ($exactLibrary !== null && !$id->equals($exactLibrary)) {
                    throw new AuthorizationException("Repair cannot replace an existing designation.");
                }
                $this->assertPersonalOwner($target, $id);
                $state = $this->preparations->find($target);
                if ($state !== null && !$state->libraryId?->equals($id)) {
                    throw new AuthorizationException("Conflicting preparation requires explicit operator diagnosis.");
                }
                return $id;
            }
            if ($exactLibrary !== null) {
                $this->transactions->run(function () use ($target, $exactLibrary): void {
                    $this->preparations->lockContext($target, $exactLibrary);
                    $this->assertOwner($target, $exactLibrary);
                    $this->designations->designate($target, $exactLibrary);
                    $this->preparations->save(new AccountPreparation($target, $exactLibrary, true));
                });
                return $exactLibrary;
            }
            if ($this->preparations->ownedLibraries($target) !== []) {
                throw new AuthorizationException("Choose an exact existing owned Library before repair.");
            }
            $id = $this->provisioner->provision($target);
            $this->assertPersonalOwner($target, $id);
            $this->preparations->save(new AccountPreparation($target, $id, true));
            return $id;
        });
    }

    /** Also used by the trusted authentication adapter before a session exists. */
    public function requireReady(UserId $userId, bool $requireNamed = true): void
    {
        $this->assertActive($userId);
        $state = $this->preparations->find($userId);
        if ($state === null && !$this->directory->isRequested($userId)) {
            return; // Historical accounts are not migrated by a login.
        }
        if ($state?->libraryId === null || ($requireNamed && !$state->named)) {
            throw new AuthorizationException("Complete Biblio account preparation and naming first.");
        }
        $this->assertPersonalOwner($userId, $state->libraryId);
    }

    /** Minimal routing signal for a WordPress-authenticated login candidate before its session exists. */
    public function requiresNamingAfterAuthentication(UserId $userId): bool
    {
        $this->requireReady($userId, false);
        $state = $this->preparations->find($userId);
        return $state !== null && !$state->named;
    }

    /** @return array{state:string,library_id:?string,needs_name:bool,name:?string,notified:bool} */
    public function myStatus(): array
    {
        $target = $this->actor->requireUserId();
        $this->assertActive($target);
        return $this->status($target);
    }

    /** @return array{state:string,library_id:?string,needs_name:bool,name:?string,notified:bool,owned_libraries:list<string>,requested:bool} */
    public function diagnose(UserId $target): array
    {
        $this->assertManagement();
        $this->assertActive($target);
        return $this->status($target) + [
            "owned_libraries" => array_map(static fn (LibraryId $id): string => $id->value(), $this->preparations->ownedLibraries($target)),
            "requested" => $this->directory->isRequested($target),
        ];
    }

    public function saveName(LibraryId $id, string $value): void
    {
        $target = $this->actor->requireUserId();
        $this->requireReady($target, false);
        $name = new LibraryName($value);
        $this->preparations->exclusively($target, function () use ($target, $id, $name): void {
            $this->transactions->run(function () use ($target, $id, $name): void {
                $this->preparations->lockContext($target, $id);
                $this->assertPersonalOwner($target, $id);
                $this->preparations->rename($id, $name);
                $state = $this->preparations->find($target);
                if ($state !== null) {
                    $this->preparations->save(new AccountPreparation($target, $id, true, $state->notified));
                }
            });
        });
    }

    /** Serialize notification retry independently from account creation. */
    public function notify(UserId $target, callable $send): bool
    {
        $this->assertManagement();
        return $this->preparations->exclusively($target, function () use ($target, $send): bool {
            $this->requireReady($target, false);
            $state = $this->preparations->find($target);
            if ($state === null || !$this->directory->isRequested($target)) {
                throw new AuthorizationException("Only explicitly prepared new accounts receive this notification.");
            }
            if ($state->notified) { return true; }
            if (!$send()) { return false; }
            $this->preparations->save(new AccountPreparation($target, $state->libraryId, $state->named, true));
            return true;
        });
    }

    /** @return array{state:string,library_id:?string,needs_name:bool,name:?string,notified:bool} */
    private function status(UserId $target): array
    {
        $state = $this->preparations->find($target);
        $id = $this->designations->findForUser($target);
        $valid = false;
        if ($id !== null) {
            try { $this->assertPersonalOwner($target, $id); $valid = true; }
            catch (AuthorizationException) { /* Diagnosis must expose a conflict without repairing it. */ }
        }
        $ready = $valid && ($state === null || ($state->libraryId !== null && $id?->equals($state->libraryId)));
        return [
            "state" => $state === null && !$this->directory->isRequested($target)
                ? ($valid ? "legacy" : "requires_review") : ($state !== null && $ready ? "ready" : "pending"),
            "library_id" => $id?->value(),
            "needs_name" => $state !== null && $ready && !$state->named,
            "name" => $valid ? $this->libraries->find($id)?->name()->value() : null,
            "notified" => $state->notified ?? false,
        ];
    }

    private function assertActive(UserId $target): void
    {
        if (!$this->directory->isActive($target)) {
            throw new AuthorizationException("An exact active platform user is required.");
        }
    }

    private function assertPersonalOwner(UserId $target, LibraryId $id): void
    {
        if (!$this->designations->findForUser($target)?->equals($id)) {
            throw new AuthorizationException("Library does not match the exact personal designation.");
        }
        $this->assertOwner($target, $id);
    }

    private function assertOwner(UserId $target, LibraryId $id): void
    {
        $library = $this->libraries->find($id);
        $membership = $this->memberships->findFor($id, $target)?->membership();
        if ($library?->type() !== LibraryType::PrivateLibrary
            || $membership?->status() !== MembershipStatus::Active
            || $membership->managementRole() !== ManagementRole::Owner
            || $membership->useAccess() !== UseAccess::Direct) {
            throw new AuthorizationException("Active personal Library ownership with direct access is required.");
        }
    }
}
