<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Library\LibraryId;

interface LibraryGenreRepository
{
    public function find(
        LibraryId $libraryId,
        LibraryGenreId $id
    ): ?LibraryGenre;

    public function findForUpdate(
        LibraryId $libraryId,
        LibraryGenreId $id
    ): ?LibraryGenre;

    public function findByNormalizedName(
        LibraryId $libraryId,
        ClassificationNormalizedName $name
    ): ?LibraryGenre;

    public function findBySeedKey(
        LibraryId $libraryId,
        ClassificationSeedKey $seedKey
    ): ?LibraryGenre;
}
