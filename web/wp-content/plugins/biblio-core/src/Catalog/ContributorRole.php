<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

enum ContributorRole: string
{
    case Author = "author";
    case CoAuthor = "co_author";
}
