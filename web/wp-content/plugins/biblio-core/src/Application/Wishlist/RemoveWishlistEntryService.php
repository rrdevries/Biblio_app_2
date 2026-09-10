<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Wishlist;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Wishlist\{WishlistEntryId,WishlistRemovalReason};

final readonly class RemoveWishlistEntryService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WishlistRecorder $recorder,
        private TransactionManager $transactions
    ) {
    }

    public function remove(
        WishlistEntryId $entryId,
        WishlistRemovalReason $reason = WishlistRemovalReason::Removed
    ): void
    {
        $owner = $this->authenticatedUser->requireUserId();
        $this->transactions->run(function () use ($owner, $entryId, $reason): void {
            $this->recorder->removeForOwner($owner, $entryId, $reason);
        });
    }
}
