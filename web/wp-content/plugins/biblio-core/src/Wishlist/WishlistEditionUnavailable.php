<?php

declare(strict_types=1);

namespace Biblio\Core\Wishlist;

use Biblio\Core\Exception\{ConflictException,FailureReason};

final class WishlistEditionUnavailable extends ConflictException
{
    public function __construct()
    {
        parent::__construct(
            "Wishlist Edition is unavailable for this Work.",
            FailureReason::WishlistEditionUnavailable
        );
    }
}
