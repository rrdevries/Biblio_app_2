<?php

declare(strict_types=1);

namespace Biblio\Core\Wishlist;

use Biblio\Core\Exception\{ConflictException,FailureReason};

final class WishlistEntryIdCollisionExhausted extends ConflictException
{
    public function __construct(WishlistEntryIdCollision $previous)
    {
        parent::__construct(
            "Could not allocate a unique Wishlist Entry ID.",
            FailureReason::WishlistEntryIdCollisionExhausted,
            $previous
        );
    }
}
