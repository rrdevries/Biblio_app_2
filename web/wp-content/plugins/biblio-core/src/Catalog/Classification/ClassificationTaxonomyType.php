<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

enum ClassificationTaxonomyType: string
{
    case BookType = "book_type";
    case Genre = "genre";
}
