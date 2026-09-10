<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Wishlist;

use Biblio\Core\Application\Identity\PlatformUserDirectory;
use Biblio\Core\Catalog\{EditionId,EditionRepository,WorkId,WorkRepository};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Wishlist\{WishlistClock,WishlistEditionUnavailable,WishlistEntry,WishlistEntryId,WishlistEntryIdCollision,WishlistEntryIdCollisionExhausted,WishlistEntryIdGenerator,WishlistEntryNotAvailable,WishlistIntentConflict,WishlistRemovalReason,WishlistTargetType,WishlistWorkUnavailable,WishlistWriteResult,WritableWishlistRepository};

/**
 * Source-neutral Wishlist participant for an existing transaction.
 *
 * Self-service boundaries resolve the authenticated owner and own the
 * transaction. MIG-FND may compose this participant for an already validated
 * target user without introducing source fields into the product model.
 */
final readonly class WishlistRecorder
{
    private const MAX_ID_RETRIES = 4;

    public function __construct(
        private PlatformUserDirectory $users,
        private WorkRepository $works,
        private EditionRepository $editions,
        private WritableWishlistRepository $wishlist,
        private WishlistEntryIdGenerator $ids,
        private WishlistClock $clock
    ) {
    }

    public function addWorkOnlyForOwner(
        UserId $ownerUserId,
        WorkId $workId
    ): WishlistWriteResult {
        $this->assertOwnerAndWork($ownerUserId, $workId);
        $now = $this->clock->now();
        $state = $this->wishlist->lockOrCreateWorkState(
            $ownerUserId,
            $workId,
            WishlistTargetType::WorkOnly,
            $now
        );
        if ($state->targetType() !== WishlistTargetType::WorkOnly) {
            throw new WishlistIntentConflict();
        }

        $entries = $this->wishlist->entriesForUserAndWork(
            $ownerUserId,
            $workId
        );
        if ($entries !== []) {
            return WishlistWriteResult::reused($entries[0]);
        }

        return WishlistWriteResult::created($this->insert(
            static fn (WishlistEntryId $id): WishlistEntry =>
                WishlistEntry::workOnly($id, $ownerUserId, $workId, $now)
        ));
    }

    public function addEditionForOwner(
        UserId $ownerUserId,
        WorkId $workId,
        EditionId $editionId
    ): WishlistWriteResult {
        $this->assertOwnerWorkAndEdition($ownerUserId, $workId, $editionId);
        $now = $this->clock->now();
        $state = $this->wishlist->lockOrCreateWorkState(
            $ownerUserId,
            $workId,
            WishlistTargetType::EditionSpecific,
            $now
        );
        if ($state->targetType() !== WishlistTargetType::EditionSpecific) {
            throw new WishlistIntentConflict();
        }

        $existing = $this->wishlist->findEditionEntry($ownerUserId, $editionId);
        if ($existing !== null) {
            return WishlistWriteResult::reused($existing);
        }

        return WishlistWriteResult::created($this->insert(
            static fn (WishlistEntryId $id): WishlistEntry =>
                WishlistEntry::editionSpecific(
                    $id,
                    $ownerUserId,
                    $workId,
                    $editionId,
                    $now
                )
        ));
    }

    public function refineWorkOnlyForOwner(
        UserId $ownerUserId,
        WorkId $workId,
        EditionId $editionId
    ): WishlistWriteResult {
        $this->assertOwnerWorkAndEdition($ownerUserId, $workId, $editionId);
        $state = $this->wishlist->lockExistingWorkState($ownerUserId, $workId);
        if ($state === null) {
            throw new WishlistEntryNotAvailable();
        }

        if ($state->targetType() === WishlistTargetType::EditionSpecific) {
            $existing = $this->wishlist->findEditionEntry(
                $ownerUserId,
                $editionId
            );
            if ($existing !== null) {
                return WishlistWriteResult::reused($existing);
            }
            throw new WishlistIntentConflict();
        }

        $entries = $this->wishlist->entriesForUserAndWork(
            $ownerUserId,
            $workId
        );
        if (count($entries) !== 1 || $entries[0]->editionId() !== null) {
            throw new ValidationException(
                "Stored Work-only Wishlist state is inconsistent."
            );
        }

        $now = $this->clock->now();
        $replacement = $entries[0]->refineToEdition($editionId, $now);
        $this->wishlist->refineWorkOnly($entries[0], $replacement, $now);

        return WishlistWriteResult::refined($replacement);
    }

    public function refineEntryToEditionForOwner(
        UserId $ownerUserId,
        WishlistEntryId $entryId,
        EditionId $editionId
    ): WishlistWriteResult {
        if (!$this->users->isActive($ownerUserId)) {
            throw new WishlistEntryNotAvailable();
        }
        $observed = $this->wishlist->findForUser($entryId, $ownerUserId);
        if ($observed === null) {
            throw new WishlistEntryNotAvailable();
        }
        $this->assertOwnerWorkAndEdition(
            $ownerUserId,
            $observed->workId(),
            $editionId
        );
        $state = $this->wishlist->lockExistingWorkState(
            $ownerUserId,
            $observed->workId()
        );
        if ($state === null) {
            throw new WishlistEntryNotAvailable();
        }
        $current = $this->wishlist->lockForUser($entryId, $ownerUserId);
        if (
            $current === null
            || !$current->workId()->equals($observed->workId())
        ) {
            throw new WishlistEntryNotAvailable();
        }
        if ($state->targetType() === WishlistTargetType::EditionSpecific) {
            if ($current->editionId()?->equals($editionId) === true) {
                return WishlistWriteResult::reused($current);
            }
            throw new WishlistIntentConflict();
        }
        if ($current->editionId() !== null) {
            throw new ValidationException(
                "Stored Work-only Wishlist state is inconsistent."
            );
        }

        $now = $this->clock->now();
        $replacement = $current->refineToEdition($editionId, $now);
        $this->wishlist->refineWorkOnly($current, $replacement, $now);

        return WishlistWriteResult::refined($replacement);
    }

    public function removeForOwner(
        UserId $ownerUserId,
        WishlistEntryId $entryId,
        WishlistRemovalReason $reason = WishlistRemovalReason::Removed
    ): void {
        if (!$this->users->isActive($ownerUserId)) {
            throw new WishlistEntryNotAvailable();
        }
        $observed = $this->wishlist->findForUser($entryId, $ownerUserId);
        if ($observed === null) {
            throw new WishlistEntryNotAvailable();
        }
        $state = $this->wishlist->lockExistingWorkState(
            $ownerUserId,
            $observed->workId()
        );
        if ($state === null) {
            throw new WishlistEntryNotAvailable();
        }
        $current = $this->wishlist->lockForUser($entryId, $ownerUserId);
        if ($current === null || !$current->workId()->equals($observed->workId())) {
            throw new WishlistEntryNotAvailable();
        }
        $this->wishlist->remove($current, $reason, $this->clock->now());
    }

    private function assertOwnerAndWork(UserId $ownerUserId, WorkId $workId): void
    {
        if (!$this->users->isActive($ownerUserId)) {
            throw new ValidationException("Wishlist requires an active user.");
        }
        if ($this->works->find($workId) === null) {
            throw new WishlistWorkUnavailable();
        }
    }

    private function assertOwnerWorkAndEdition(
        UserId $ownerUserId,
        WorkId $workId,
        EditionId $editionId
    ): void {
        $this->assertOwnerAndWork($ownerUserId, $workId);
        $edition = $this->editions->find($editionId);
        if ($edition === null || !$edition->workId()->equals($workId)) {
            throw new WishlistEditionUnavailable();
        }
    }

    /** @param callable(WishlistEntryId): WishlistEntry $factory */
    private function insert(callable $factory): WishlistEntry
    {
        $attempt = 0;
        while (true) {
            $entry = $factory($this->ids->next());
            try {
                $this->wishlist->add($entry);
                return $entry;
            } catch (WishlistEntryIdCollision $collision) {
                if (++$attempt >= self::MAX_ID_RETRIES) {
                    throw new WishlistEntryIdCollisionExhausted($collision);
                }
            }
        }
    }
}
