<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Library\LibraryId;

interface LibraryWorkRepresentationRepository
{
    public function findRepresentedWork(
        LibraryId $libraryId,
        WorkId $workId
    ): ?Work;
}
