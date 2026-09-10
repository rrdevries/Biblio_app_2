<?php

declare(strict_types=1);

namespace Biblio\Core\Wishlist;

use Biblio\Core\Exception\{ConflictException,FailureReason};

final class WishlistEntryNotAvailable extends ConflictException
{
    public function __construct()
    {
        parent::__construct(
            "Wishlist Entry is not available.",
            FailureReason::WishlistEntryNotAvailable
        );
    }
}
