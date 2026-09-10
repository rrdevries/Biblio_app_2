<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Wishlist\{WishlistEntryId,WishlistEntryIdGenerator};

final readonly class OpaqueWishlistEntryIdGenerator implements WishlistEntryIdGenerator
{
    public function next(): WishlistEntryId
    {
        return new WishlistEntryId("wish-" . bin2hex(random_bytes(16)));
    }
}
