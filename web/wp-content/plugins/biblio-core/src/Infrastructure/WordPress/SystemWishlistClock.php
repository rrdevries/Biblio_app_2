<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Wishlist\WishlistClock;
use DateTimeImmutable;
use DateTimeZone;

final readonly class SystemWishlistClock implements WishlistClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("now", new DateTimeZone("UTC"));
    }
}
