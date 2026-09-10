<?php

declare(strict_types=1);

namespace Biblio\Core\Wishlist;

enum WishlistRemovalReason: string
{
    case Fulfilled = "fulfilled";
    case Removed = "removed";
    case ReadAndRemoved = "read_and_removed";
}
