<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Library\LibraryId;

final readonly class LibraryCatalogContext
{
    public function __construct(
        private LibraryId $libraryId,
        private WorkId $workId,
        private LibraryCatalogSelection $classification,
        private LibraryCatalogContextVersion $version
    ) {
    }

    public static function create(
        LibraryId $libraryId,
        WorkId $workId,
        LibraryCatalogSelection $classification
    ): self {
        return new self(
            $libraryId,
            $workId,
            $classification,
            LibraryCatalogContextVersion::initial()
        );
    }

    public function libraryId(): LibraryId
    {
        return $this->libraryId;
    }

    public function workId(): WorkId
    {
        return $this->workId;
    }

    public function classification(): LibraryCatalogSelection
    {
        return $this->classification;
    }

    public function version(): LibraryCatalogContextVersion
    {
        return $this->version;
    }

    public function hasSameClassification(
        LibraryCatalogSelection $classification
    ): bool {
        return $this->classification->equals($classification);
    }
}
