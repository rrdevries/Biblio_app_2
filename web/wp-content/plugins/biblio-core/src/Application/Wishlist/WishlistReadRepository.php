<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Wishlist;

use Biblio\Core\Identity\UserId;

interface WishlistReadRepository
{
    /** @return list<WishlistEntryView> */
    public function findForUser(UserId $ownerUserId): array;
}
