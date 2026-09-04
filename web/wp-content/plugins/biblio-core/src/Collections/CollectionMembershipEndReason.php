<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

enum CollectionMembershipEndReason: string
{
    case Removed = 'removed';
    case ItemArchived = 'item_archived';
}
