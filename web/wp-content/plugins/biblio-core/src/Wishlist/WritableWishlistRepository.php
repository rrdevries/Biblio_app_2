<?php

declare(strict_types=1);

namespace Biblio\Core\Wishlist;

use Biblio\Core\Catalog\{EditionId,WorkId};
use Biblio\Core\Identity\UserId;
use DateTimeImmutable;

interface WritableWishlistRepository
{
    public function lockOrCreateWorkState(
        UserId $ownerUserId,
        WorkId $workId,
        WishlistTargetType $initialType,
        DateTimeImmutable $now
    ): WishlistWorkState;

    public function lockExistingWorkState(
        UserId $ownerUserId,
        WorkId $workId
    ): ?WishlistWorkState;

    /** @return list<WishlistEntry> */
    public function entriesForUserAndWork(
        UserId $ownerUserId,
        WorkId $workId
    ): array;

    public function findForUser(
        WishlistEntryId $entryId,
        UserId $ownerUserId
    ): ?WishlistEntry;

    public function lockForUser(
        WishlistEntryId $entryId,
        UserId $ownerUserId
    ): ?WishlistEntry;

    public function findEditionEntry(
        UserId $ownerUserId,
        EditionId $editionId
    ): ?WishlistEntry;

    public function add(WishlistEntry $entry): void;

    public function refineWorkOnly(
        WishlistEntry $current,
        WishlistEntry $replacement,
        DateTimeImmutable $updatedAt
    ): void;

    public function remove(
        WishlistEntry $entry,
        WishlistRemovalReason $reason,
        DateTimeImmutable $removedAt
    ): void;
}
