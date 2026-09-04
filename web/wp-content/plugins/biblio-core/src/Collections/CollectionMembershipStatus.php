<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

enum CollectionMembershipStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
