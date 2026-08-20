<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

enum ClassificationTermStatus: string
{
    case Active = "active";
    case Inactive = "inactive";
}
