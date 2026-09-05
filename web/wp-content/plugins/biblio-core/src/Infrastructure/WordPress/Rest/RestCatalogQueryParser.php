<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Application\Catalog\Query\{CatalogArchiveScope,CatalogCollectionFilter,CatalogFilters,CatalogQuery,CatalogQueryCursor,CatalogQuerySort,CatalogSearchTerm};
use Biblio\Core\Application\Catalog\Read\CatalogOverviewPageSize;
use Biblio\Core\Catalog\Classification\{LibraryBookTypeId,LibraryGenreId,LibrarySubjectId};
use Biblio\Core\Catalog\{AuthorId,LocationId,SeriesId};
use Biblio\Core\Collections\CollectionId;
use Biblio\Core\Reading\PersonalWorkReadingStatus;
use Throwable;

final readonly class RestCatalogQueryParser
{
    public const MAXIMUM_FILTER_VALUES = 100;

    private const FIELDS = [
        'search',
        'reading_statuses',
        'author_ids',
        'series_ids',
        'location_ids',
        'book_type_ids',
        'genre_ids',
        'subject_ids',
        'collection_ids',
        'without_collection',
        'sort',
        'page_size',
        'archive_scope',
        'cursor',
    ];

    /** @param array<string, mixed> $parameters */
    public function parse(array $parameters): CatalogQuery
    {
        if (array_diff(array_keys($parameters), self::FIELDS) !== []) {
            throw RestRequestException::unknownFields();
        }

        $collections = $this->collectionFilter($parameters);

        try {
            return new CatalogQuery(
                filters: new CatalogFilters(
                    readingStatuses: $this->enumList(
                        $parameters,
                        'reading_statuses',
                        PersonalWorkReadingStatus::class
                    ),
                    authorIds: $this->idList(
                        $parameters,
                        'author_ids',
                        static fn (string $value): AuthorId => new AuthorId($value)
                    ),
                    seriesIds: $this->idList(
                        $parameters,
                        'series_ids',
                        static fn (string $value): SeriesId => new SeriesId($value)
                    ),
                    locationIds: $this->idList(
                        $parameters,
                        'location_ids',
                        static fn (string $value): LocationId => new LocationId($value)
                    ),
                    bookTypeIds: $this->idList(
                        $parameters,
                        'book_type_ids',
                        static fn (string $value): LibraryBookTypeId => new LibraryBookTypeId($value)
                    ),
                    genreIds: $this->idList(
                        $parameters,
                        'genre_ids',
                        static fn (string $value): LibraryGenreId => new LibraryGenreId($value)
                    ),
                    subjectIds: $this->idList(
                        $parameters,
                        'subject_ids',
                        static fn (string $value): LibrarySubjectId => new LibrarySubjectId($value)
                    ),
                    collections: $collections
                ),
                sort: $this->sort($parameters),
                pageSize: $this->pageSize($parameters),
                search: $this->search($parameters),
                archiveScope: $this->archiveScope($parameters),
                cursor: $this->cursor($parameters)
            );
        } catch (RestRequestException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw RestRequestException::invalid('catalog_query');
        }
    }

    /** @param array<string, mixed> $parameters */
    private function search(array $parameters): ?CatalogSearchTerm
    {
        if (!array_key_exists('search', $parameters)) {
            return null;
        }

        $value = $this->scalarString($parameters['search'], 'search');
        $value = trim($value);

        try {
            return new CatalogSearchTerm($value);
        } catch (Throwable) {
            throw RestRequestException::invalid('search');
        }
    }

    /** @param array<string, mixed> $parameters */
    private function sort(array $parameters): CatalogQuerySort
    {
        if (!array_key_exists('sort', $parameters)) {
            return CatalogQuerySort::Title;
        }

        return CatalogQuerySort::tryFrom(
            $this->scalarString($parameters['sort'], 'sort')
        ) ?? throw RestRequestException::invalid('sort');
    }

    /** @param array<string, mixed> $parameters */
    private function archiveScope(array $parameters): CatalogArchiveScope
    {
        if (!array_key_exists('archive_scope', $parameters)) {
            return CatalogArchiveScope::ActiveOnly;
        }

        return CatalogArchiveScope::tryFrom(
            $this->scalarString($parameters['archive_scope'], 'archive_scope')
        ) ?? throw RestRequestException::invalid('archive_scope');
    }

    /** @param array<string, mixed> $parameters */
    private function pageSize(array $parameters): CatalogOverviewPageSize
    {
        if (!array_key_exists('page_size', $parameters)) {
            return new CatalogOverviewPageSize();
        }

        $value = $parameters['page_size'];
        if (!(is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1))) {
            throw RestRequestException::wrongType('page_size', 'an integer');
        }

        try {
            return new CatalogOverviewPageSize((int) $value);
        } catch (Throwable) {
            throw RestRequestException::invalid('page_size');
        }
    }

    /** @param array<string, mixed> $parameters */
    private function cursor(array $parameters): ?CatalogQueryCursor
    {
        if (!array_key_exists('cursor', $parameters)) {
            return null;
        }

        try {
            return new CatalogQueryCursor(
                $this->scalarString($parameters['cursor'], 'cursor')
            );
        } catch (RestRequestException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw RestRequestException::invalid('cursor');
        }
    }

    /** @param array<string, mixed> $parameters */
    private function collectionFilter(array $parameters): CatalogCollectionFilter
    {
        $ids = $this->idList(
            $parameters,
            'collection_ids',
            static fn (string $value): CollectionId => new CollectionId($value)
        );
        $without = array_key_exists('without_collection', $parameters)
            ? $this->boolean($parameters['without_collection'], 'without_collection')
            : false;

        try {
            if ($without && $ids !== []) {
                throw RestRequestException::invalid('collection_ids');
            }
            if ($without) {
                return CatalogCollectionFilter::withoutCollection();
            }
            return $ids === []
                ? CatalogCollectionFilter::any()
                : CatalogCollectionFilter::in($ids);
        } catch (Throwable) {
            throw RestRequestException::invalid('collection_ids');
        }
    }

    /**
     * @template T of object
     * @param array<string, mixed> $parameters
     * @param callable(string): T $create
     * @return list<T>
     */
    private function idList(array $parameters, string $field, callable $create): array
    {
        if (!array_key_exists($field, $parameters)) {
            return [];
        }

        $values = $this->stringList($parameters[$field], $field);
        $result = [];

        try {
            foreach ($values as $value) {
                $result[] = $create($value);
            }
        } catch (Throwable) {
            throw RestRequestException::invalid($field);
        }

        return $result;
    }

    /**
     * @template T of \BackedEnum
     * @param array<string, mixed> $parameters
     * @param class-string<T> $enum
     * @return list<T>
     */
    private function enumList(array $parameters, string $field, string $enum): array
    {
        if (!array_key_exists($field, $parameters)) {
            return [];
        }

        $result = [];
        foreach ($this->stringList($parameters[$field], $field) as $value) {
            $parsed = $enum::tryFrom($value);
            if ($parsed === null) {
                throw RestRequestException::invalid($field);
            }
            $result[] = $parsed;
        }

        return $result;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw RestRequestException::wrongType($field, 'an array');
        }
        if ($value === [] || count($value) > self::MAXIMUM_FILTER_VALUES) {
            throw RestRequestException::invalid($field);
        }

        $result = [];
        foreach ($value as $entry) {
            if (!is_string($entry) || $entry === '' || $entry !== trim($entry)) {
                throw RestRequestException::invalid($field);
            }
            if (isset($result[$entry])) {
                throw RestRequestException::invalid($field);
            }
            $result[$entry] = $entry;
        }

        return array_values($result);
    }

    private function scalarString(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw RestRequestException::wrongType($field, 'a string');
        }
        if ($value === '') {
            throw RestRequestException::invalid($field);
        }
        return $value;
    }

    private function boolean(mixed $value, string $field): bool
    {
        if ($value === true || $value === 'true') {
            return true;
        }
        if ($value === false || $value === 'false') {
            return false;
        }

        throw RestRequestException::wrongType($field, 'a boolean');
    }
}
