<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Query;

enum CatalogQuerySort: string
{
    case Title = 'title';
    case Author = 'author';
    case Series = 'series';
}
