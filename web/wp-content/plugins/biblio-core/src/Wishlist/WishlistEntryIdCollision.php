<?php

declare(strict_types=1);

namespace Biblio\Core\Wishlist;

use RuntimeException;
use Throwable;

final class WishlistEntryIdCollision extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct("Wishlist Entry ID already exists.", 0, $previous);
    }
}
