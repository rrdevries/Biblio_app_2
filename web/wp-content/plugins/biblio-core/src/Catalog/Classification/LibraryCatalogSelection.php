<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Exception\ValidationException;

final readonly class LibraryCatalogSelection
{
    /** @var list<LibraryGenreId> */
    private array $genreIds;

    /** @var list<LibrarySubjectId> */
    private array $subjectIds;

    /**
     * @param list<LibraryGenreId> $genreIds
     * @param list<LibrarySubjectId> $subjectIds
     */
    public function __construct(
        private LibraryBookTypeId $bookTypeId,
        array $genreIds = [],
        array $subjectIds = []
    ) {
        $this->genreIds = self::normalizeGenreIds($genreIds);
        $this->subjectIds = self::normalizeSubjectIds($subjectIds);
    }

    public function bookTypeId(): LibraryBookTypeId
    {
        return $this->bookTypeId;
    }

    /** @return list<LibraryGenreId> */
    public function genreIds(): array
    {
        return $this->genreIds;
    }

    /** @return list<LibrarySubjectId> */
    public function subjectIds(): array
    {
        return $this->subjectIds;
    }

    public function equals(self $other): bool
    {
        if (!$this->bookTypeId->equals($other->bookTypeId)) {
            return false;
        }

        return self::values($this->genreIds) === self::values($other->genreIds)
            && self::values($this->subjectIds) === self::values($other->subjectIds);
    }

    /**
     * @param list<LibraryGenreId> $ids
     * @return list<LibraryGenreId>
     */
    private static function normalizeGenreIds(array $ids): array
    {
        return self::normalizeIds(
            $ids,
            LibraryGenreId::class,
            "Library Genre IDs"
        );
    }

    /**
     * @param list<LibrarySubjectId> $ids
     * @return list<LibrarySubjectId>
     */
    private static function normalizeSubjectIds(array $ids): array
    {
        return self::normalizeIds(
            $ids,
            LibrarySubjectId::class,
            "Library Subject IDs"
        );
    }

    /**
     * @template T of LibraryGenreId|LibrarySubjectId
     * @param list<T> $ids
     * @param class-string<T> $expectedClass
     * @return list<T>
     */
    private static function normalizeIds(
        array $ids,
        string $expectedClass,
        string $label
    ): array {
        $byValue = [];

        foreach ($ids as $id) {
            if (!$id instanceof $expectedClass) {
                throw new ValidationException("{$label} contain an invalid type.");
            }

            if (isset($byValue[$id->value()])) {
                throw new ValidationException("{$label} must be duplicate-free.");
            }

            $byValue[$id->value()] = $id;
        }

        ksort($byValue, SORT_STRING);

        return array_values($byValue);
    }

    /**
     * @param list<LibraryGenreId>|list<LibrarySubjectId> $ids
     * @return list<string>
     */
    private static function values(array $ids): array
    {
        return array_map(
            static fn (LibraryGenreId|LibrarySubjectId $id): string => $id->value(),
            $ids
        );
    }
}
