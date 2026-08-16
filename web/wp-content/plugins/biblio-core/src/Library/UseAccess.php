<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

enum UseAccess: string
{
    case Direct = "direct";
    case Borrow = "borrow";
    case ViewOnly = "view_only";
}
