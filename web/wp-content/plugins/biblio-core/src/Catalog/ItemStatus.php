<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

enum ItemStatus: string
{
    case Active = "active";
    case Archived = "archived";
}
