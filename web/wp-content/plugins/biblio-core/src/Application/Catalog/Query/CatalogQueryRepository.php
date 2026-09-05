<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Query;

use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;

interface CatalogQueryRepository
{
    public function page(
        LibraryId $libraryId,
        UserId $actorId,
        CatalogQuery $query,
        ?ItemId $afterItemId
    ): CatalogQueryRecordPage;
}
