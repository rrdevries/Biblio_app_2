<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

enum CollectionStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
