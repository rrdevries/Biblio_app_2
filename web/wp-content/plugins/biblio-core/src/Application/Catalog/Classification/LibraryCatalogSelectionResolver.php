<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Classification;

use Biblio\Core\Catalog\Classification\LibraryBookType;
use Biblio\Core\Catalog\Classification\LibraryBookTypeRepository;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\Classification\LibraryGenre;
use Biblio\Core\Catalog\Classification\LibraryGenreRepository;
use Biblio\Core\Catalog\Classification\LibrarySubject;
use Biblio\Core\Catalog\Classification\LibrarySubjectRepository;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;

final readonly class LibraryCatalogSelectionResolver
{
    public function __construct(
        private LibraryBookTypeRepository $bookTypes,
        private LibraryGenreRepository $genres,
        private LibrarySubjectRepository $subjects
    ) {
    }

    public function lockAndResolve(
        LibraryId $libraryId,
        LibraryCatalogSelection $selection
    ): LibraryCatalogSelectionSnapshot {
        $bookType = $this->bookTypes->findForUpdate(
            $libraryId,
            $selection->bookTypeId()
        );

        if ($bookType === null) {
            throw new ValidationException(
                "Selected Library Book Type does not exist in this Library."
            );
        }

        $genres = [];

        foreach ($selection->genreIds() as $id) {
            $genre = $this->genres->findForUpdate($libraryId, $id);

            if ($genre === null) {
                throw new ValidationException(
                    "Selected Library Genre does not exist in this Library."
                );
            }

            $genres[] = $this->genre($genre);
        }

        $subjects = [];

        foreach ($selection->subjectIds() as $id) {
            $subject = $this->subjects->findForUpdate($libraryId, $id);

            if ($subject === null) {
                throw new ValidationException(
                    "Selected Library Subject does not exist in this Library."
                );
            }

            $subjects[] = $this->subject($subject);
        }

        return new LibraryCatalogSelectionSnapshot(
            $this->bookType($bookType),
            $genres,
            $subjects
        );
    }

    /** @return array{id: string, label: string, status: \Biblio\Core\Catalog\Classification\ClassificationTermStatus} */
    private function bookType(LibraryBookType $term): array
    {
        return [
            "id" => $term->id()->value(),
            "label" => $term->name()->value(),
            "status" => $term->status(),
        ];
    }

    /** @return array{id: string, label: string, status: \Biblio\Core\Catalog\Classification\ClassificationTermStatus} */
    private function genre(LibraryGenre $term): array
    {
        return [
            "id" => $term->id()->value(),
            "label" => $term->name()->value(),
            "status" => $term->status(),
        ];
    }

    /** @return array{id: string, label: string, status: \Biblio\Core\Catalog\Classification\ClassificationTermStatus} */
    private function subject(LibrarySubject $term): array
    {
        return [
            "id" => $term->id()->value(),
            "label" => $term->name()->value(),
            "status" => $term->status(),
        ];
    }
}
