<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Wishlist;

use Biblio\Core\Application\Identity\PlatformUserDirectory;
use Biblio\Core\Catalog\{WorkId,WorkRepository};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Wishlist\{
    WishlistEntry,
    WishlistEntryId,
    WishlistEntryIdCollision,
    WishlistEntryIdCollisionExhausted,
    WishlistEntryIdGenerator,
    WishlistIntentConflict,
    WishlistTargetType,
    WishlistWorkUnavailable,
    WishlistWriteResult,
    WritableWishlistRepository
};
use DateTimeImmutable;

/** Migration-only product seam; joins the caller-owned transaction. */
final readonly class HistoricalWishlistRecorder
{
    private const MAX_ID_RETRIES = 4;

    public function __construct(
        private PlatformUserDirectory $users,
        private WorkRepository $works,
        private WritableWishlistRepository $wishlist,
        private WishlistEntryIdGenerator $ids
    ) {
    }

    public function addWorkOnlyForOwner(
        UserId $ownerUserId,
        WorkId $workId,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt
    ): WishlistWriteResult {
        if (!$this->users->isActive($ownerUserId)) {
            throw new ValidationException("Wishlist requires an active user.");
        }
        if ($this->works->find($workId) === null) {
            throw new WishlistWorkUnavailable();
        }

        $state = $this->wishlist->lockOrCreateWorkState(
            $ownerUserId,
            $workId,
            WishlistTargetType::WorkOnly,
            $createdAt
        );
        if ($state->targetType() !== WishlistTargetType::WorkOnly) {
            throw new WishlistIntentConflict();
        }

        $entries = $this->wishlist->entriesForUserAndWork(
            $ownerUserId,
            $workId
        );
        if ($entries !== []) {
            if (count($entries) !== 1 || $entries[0]->editionId() !== null) {
                throw new ValidationException(
                    "Stored Work-only Wishlist state is inconsistent."
                );
            }
            return WishlistWriteResult::reused($entries[0]);
        }

        return WishlistWriteResult::created($this->insert(
            static fn (WishlistEntryId $id): WishlistEntry =>
                new WishlistEntry(
                    $id,
                    $ownerUserId,
                    $workId,
                    null,
                    $createdAt,
                    $updatedAt
                )
        ));
    }

    /** @param callable(WishlistEntryId):WishlistEntry $factory */
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
