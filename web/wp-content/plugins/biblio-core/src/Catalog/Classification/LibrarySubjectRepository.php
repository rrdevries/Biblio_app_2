<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Library\LibraryId;

interface LibrarySubjectRepository
{
    public function find(
        LibraryId $libraryId,
        LibrarySubjectId $id
    ): ?LibrarySubject;

    public function findForUpdate(
        LibraryId $libraryId,
        LibrarySubjectId $id
    ): ?LibrarySubject;

    public function findByNormalizedName(
        LibraryId $libraryId,
        ClassificationNormalizedName $name
    ): ?LibrarySubject;

    public function findBySeedKey(
        LibraryId $libraryId,
        ClassificationSeedKey $seedKey
    ): ?LibrarySubject;
}
