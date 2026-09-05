<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Query;

use Biblio\Core\Catalog\{AuthorId,LocationId,SeriesId};
use Biblio\Core\Catalog\Classification\{LibraryBookTypeId,LibraryGenreId,LibrarySubjectId};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Reading\PersonalWorkReadingStatus;

final readonly class CatalogFilters
{
    private CatalogCollectionFilter $collections;

    /**
     * @param list<PersonalWorkReadingStatus> $readingStatuses
     * @param list<AuthorId> $authorIds
     * @param list<SeriesId> $seriesIds
     * @param list<LocationId> $locationIds
     * @param list<LibraryBookTypeId> $bookTypeIds
     * @param list<LibraryGenreId> $genreIds
     * @param list<LibrarySubjectId> $subjectIds
     */
    public function __construct(
        private array $readingStatuses = [],
        private array $authorIds = [],
        private array $seriesIds = [],
        private array $locationIds = [],
        private array $bookTypeIds = [],
        private array $genreIds = [],
        private array $subjectIds = [],
        ?CatalogCollectionFilter $collections = null
    ) {
        $this->collections = $collections ?? CatalogCollectionFilter::any();
        self::assertEnumList($readingStatuses, PersonalWorkReadingStatus::class, 'reading status');
        self::assertIdList($authorIds, AuthorId::class, 'Author');
        self::assertIdList($seriesIds, SeriesId::class, 'Series');
        self::assertIdList($locationIds, LocationId::class, 'Location');
        self::assertIdList($bookTypeIds, LibraryBookTypeId::class, 'Book Type');
        self::assertIdList($genreIds, LibraryGenreId::class, 'Genre');
        self::assertIdList($subjectIds, LibrarySubjectId::class, 'Subject');
    }

    public static function none(): self { return new self(); }

    /** @return list<PersonalWorkReadingStatus> */ public function readingStatuses(): array { return $this->readingStatuses; }
    /** @return list<AuthorId> */ public function authorIds(): array { return $this->authorIds; }
    /** @return list<SeriesId> */ public function seriesIds(): array { return $this->seriesIds; }
    /** @return list<LocationId> */ public function locationIds(): array { return $this->locationIds; }
    /** @return list<LibraryBookTypeId> */ public function bookTypeIds(): array { return $this->bookTypeIds; }
    /** @return list<LibraryGenreId> */ public function genreIds(): array { return $this->genreIds; }
    /** @return list<LibrarySubjectId> */ public function subjectIds(): array { return $this->subjectIds; }
    public function collections(): CatalogCollectionFilter { return $this->collections; }

    /** @param array<mixed> $values */
    private static function assertIdList(array $values, string $class, string $label): void
    {
        $seen = [];
        foreach ($values as $value) {
            if (!$value instanceof $class || isset($seen[$value->value()])) {
                throw new ValidationException("Catalog {$label} filters must be typed and unique.");
            }
            $seen[$value->value()] = true;
        }
    }

    /** @param array<mixed> $values */
    private static function assertEnumList(array $values, string $class, string $label): void
    {
        $seen = [];
        foreach ($values as $value) {
            if (!$value instanceof $class || isset($seen[$value->value])) {
                throw new ValidationException("Catalog {$label} filters must be typed and unique.");
            }
            $seen[$value->value] = true;
        }
    }
}
