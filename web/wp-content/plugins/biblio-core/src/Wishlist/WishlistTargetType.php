<?php

declare(strict_types=1);

namespace Biblio\Core\Wishlist;

enum WishlistTargetType: string
{
    case WorkOnly = "work_only";
    case EditionSpecific = "edition_specific";
}
