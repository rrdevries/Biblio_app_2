<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Library\LibraryId;

interface LibraryCatalogContextRepository
{
    public function find(
        LibraryId $libraryId,
        WorkId $workId
    ): ?LibraryCatalogContext;

    public function findForUpdate(
        LibraryId $libraryId,
        WorkId $workId
    ): ?LibraryCatalogContext;
}
