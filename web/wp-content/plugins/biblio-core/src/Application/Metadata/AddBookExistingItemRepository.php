<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Library\LibraryId;

interface AddBookExistingItemRepository
{
    /**
     * @param list<EditionId> $editionIds
     * @return array<string, list<AddBookExistingItem>>
     */
    public function forEditionsInLibrary(
        LibraryId $libraryId,
        array $editionIds
    ): array;
}
