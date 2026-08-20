<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Library\LibraryId;

interface WritableLibraryBookTypeRepository extends LibraryBookTypeRepository
{
    public function add(LibraryBookType $term): void;

    public function rename(
        LibraryId $libraryId,
        LibraryBookTypeId $id,
        ClassificationTermName $name,
        ClassificationNormalizedName $normalizedName
    ): void;

    public function changeStatus(
        LibraryId $libraryId,
        LibraryBookTypeId $id,
        ClassificationTermStatus $status
    ): void;

    public function adoptSeedKey(
        LibraryId $libraryId,
        LibraryBookTypeId $id,
        ClassificationSeedKey $seedKey
    ): bool;
}
