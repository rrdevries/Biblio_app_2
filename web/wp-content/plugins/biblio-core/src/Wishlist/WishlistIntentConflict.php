<?php

declare(strict_types=1);

namespace Biblio\Core\Wishlist;

use Biblio\Core\Exception\{ConflictException,FailureReason};

final class WishlistIntentConflict extends ConflictException
{
    public function __construct()
    {
        parent::__construct(
            "Wishlist intent requires an explicit Work/Edition choice.",
            FailureReason::WishlistIntentConflict
        );
    }
}
