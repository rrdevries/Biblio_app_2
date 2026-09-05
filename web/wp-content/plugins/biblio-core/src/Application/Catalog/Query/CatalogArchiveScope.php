<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Query;

enum CatalogArchiveScope: string
{
    case ActiveOnly = 'active_only';
    case ActiveAndArchived = 'active_and_archived';
}
