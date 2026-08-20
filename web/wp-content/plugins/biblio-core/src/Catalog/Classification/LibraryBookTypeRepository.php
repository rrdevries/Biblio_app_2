<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Library\LibraryId;

interface LibraryBookTypeRepository
{
    public function find(
        LibraryId $libraryId,
        LibraryBookTypeId $id
    ): ?LibraryBookType;

    public function findForUpdate(
        LibraryId $libraryId,
        LibraryBookTypeId $id
    ): ?LibraryBookType;

    public function findByNormalizedName(
        LibraryId $libraryId,
        ClassificationNormalizedName $name
    ): ?LibraryBookType;

    public function findBySeedKey(
        LibraryId $libraryId,
        ClassificationSeedKey $seedKey
    ): ?LibraryBookType;

    public function countActive(LibraryId $libraryId): int;
}
