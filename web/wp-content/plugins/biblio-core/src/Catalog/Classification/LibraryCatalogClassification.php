<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

final readonly class LibraryCatalogClassification
{
    /**
     * @param list<LibraryGenre> $genres
     * @param list<LibrarySubject> $subjects
     */
    public function __construct(
        private LibraryBookType $bookType,
        private array $genres,
        private array $subjects
    ) {
    }

    public function bookType(): LibraryBookType
    {
        return $this->bookType;
    }

    /** @return list<LibraryGenre> */
    public function genres(): array
    {
        return $this->genres;
    }

    /** @return list<LibrarySubject> */
    public function subjects(): array
    {
        return $this->subjects;
    }
}
