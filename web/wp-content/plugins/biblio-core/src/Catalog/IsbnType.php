<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

enum IsbnType: string
{
    case Isbn10 = "isbn_10";
    case Isbn13 = "isbn_13";
}
