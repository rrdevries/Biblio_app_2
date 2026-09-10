<?php

declare(strict_types=1);

namespace Biblio\Core\Wishlist;

use Biblio\Core\Exception\{ConflictException,FailureReason};

final class WishlistWorkUnavailable extends ConflictException
{
    public function __construct()
    {
        parent::__construct(
            "Wishlist Work is unavailable.",
            FailureReason::WishlistWorkUnavailable
        );
    }
}
