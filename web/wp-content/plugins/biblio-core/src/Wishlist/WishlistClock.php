<?php

declare(strict_types=1);

namespace Biblio\Core\Wishlist;

use DateTimeImmutable;

interface WishlistClock
{
    public function now(): DateTimeImmutable;
}
