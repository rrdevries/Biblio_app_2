<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

enum AuthorIdentityStatus: string
{
    case Provisional = "provisional";
    case Resolved = "resolved";
}
