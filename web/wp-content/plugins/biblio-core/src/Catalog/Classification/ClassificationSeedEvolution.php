<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Library\LibraryId;

interface ClassificationSeedEvolution
{
    /** Must be called inside the owning application or migration transaction. */
    public function evolve(LibraryId $libraryId): void;

    public function isConverged(LibraryId $libraryId): bool;

    /** @return list<ClassificationSeedAdoptionAmbiguity> */
    public function ambiguities(
        LibraryId $libraryId
    ): array;
}
