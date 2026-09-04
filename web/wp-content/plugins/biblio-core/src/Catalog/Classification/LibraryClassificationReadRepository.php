<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Library\LibraryId;

interface LibraryClassificationReadRepository
{
    /** @return list<LibraryBookType> */
    public function activeBookTypes(LibraryId $libraryId): array;

    /** @return list<LibraryGenre> */
    public function activeGenres(LibraryId $libraryId): array;

    /** @return list<LibrarySubject> */
    public function activeSubjects(LibraryId $libraryId): array;

    /**
     * @param list<WorkId> $workIds
     * @return array<string, LibraryCatalogSelection|null>
     */
    public function classificationsForWorks(LibraryId $libraryId, array $workIds): array;
}
