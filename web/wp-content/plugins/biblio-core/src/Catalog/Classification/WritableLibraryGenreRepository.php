<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Library\LibraryId;

interface WritableLibraryGenreRepository extends LibraryGenreRepository
{
    public function add(LibraryGenre $term): void;

    public function rename(
        LibraryId $libraryId,
        LibraryGenreId $id,
        ClassificationTermName $name,
        ClassificationNormalizedName $normalizedName
    ): void;

    public function changeStatus(
        LibraryId $libraryId,
        LibraryGenreId $id,
        ClassificationTermStatus $status
    ): void;

    public function adoptSeedKey(
        LibraryId $libraryId,
        LibraryGenreId $id,
        ClassificationSeedKey $seedKey
    ): bool;
}
