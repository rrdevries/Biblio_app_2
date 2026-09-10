<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Wishlist;

use Biblio\Core\Application\Identity\AuthenticatedUser;

final readonly class GetMyWishlistService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WishlistReadRepository $wishlist
    ) {
    }

    /** @return list<WishlistEntryView> */
    public function get(): array
    {
        return $this->wishlist->findForUser(
            $this->authenticatedUser->requireUserId()
        );
    }
}
