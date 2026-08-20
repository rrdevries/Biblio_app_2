<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Library\LibraryId;

interface WritableLibrarySubjectRepository extends LibrarySubjectRepository
{
    public function add(LibrarySubject $term): void;

    public function rename(
        LibraryId $libraryId,
        LibrarySubjectId $id,
        ClassificationTermName $name,
        ClassificationNormalizedName $normalizedName
    ): void;

    public function changeStatus(
        LibraryId $libraryId,
        LibrarySubjectId $id,
        ClassificationTermStatus $status
    ): void;

    public function adoptSeedKey(
        LibraryId $libraryId,
        LibrarySubjectId $id,
        ClassificationSeedKey $seedKey
    ): bool;
}
