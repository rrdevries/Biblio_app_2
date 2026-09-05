<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Catalog\Query\{CatalogArchiveScope,CatalogCollectionFilter,CatalogFilters,CatalogQuery,CatalogQueryCursor,CatalogQueryCursorCodec,CatalogQuerySort,CatalogSearchTerm};
use Biblio\Core\Application\Catalog\Read\CatalogOverviewPageSize;
use Biblio\Core\Catalog\{AuthorId,ItemId,SeriesId};
use Biblio\Core\Collections\CollectionId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CatalogQueryModelTest extends TestCase
{
    public function testDefaultsAreTypedAndImmutable(): void
    {
        $query = new CatalogQuery();
        self::assertSame(CatalogQuerySort::Title, $query->sort());
        self::assertSame(CatalogArchiveScope::ActiveOnly, $query->archiveScope());
        self::assertSame(CatalogOverviewPageSize::DEFAULT, $query->pageSize()->value());
        self::assertNull($query->search());
        self::assertNull($query->cursor());
        self::assertFalse($query->filters()->collections()->isActive());
        self::assertTrue((new ReflectionClass($query))->isReadOnly());
    }

    public function testFiltersRejectDuplicateAndUntypedValues(): void
    {
        foreach ([
            static fn (): CatalogFilters => new CatalogFilters(authorIds: [new AuthorId('a'), new AuthorId('a')]),
            static fn (): CatalogFilters => new CatalogFilters(readingStatuses: [\Biblio\Core\Reading\PersonalWorkReadingStatus::Read, \Biblio\Core\Reading\PersonalWorkReadingStatus::Read]),
            /** @phpstan-ignore argument.type */
            static fn (): CatalogFilters => new CatalogFilters(seriesIds: ['series']),
            static fn (): CatalogCollectionFilter => CatalogCollectionFilter::in([]),
        ] as $operation) {
            try {
                $operation();
                self::fail('Invalid catalog filter input was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    #[DataProvider('invalidPageSizes')]
    public function testPageSizeIsBounded(int $value): void
    {
        $this->expectException(ValidationException::class);
        new CatalogOverviewPageSize($value);
    }

    /** @return iterable<string,array{int}> */
    public static function invalidPageSizes(): iterable
    {
        yield 'zero' => [0];
        yield 'above maximum' => [CatalogOverviewPageSize::MAXIMUM + 1];
    }

    #[DataProvider('invalidSearchTerms')]
    public function testSearchTermIsBounded(string $value): void
    {
        $this->expectException(ValidationException::class);
        new CatalogSearchTerm($value);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidSearchTerms(): iterable
    {
        yield 'short' => ['x'];
        yield 'whitespace' => [' title '];
        yield 'long' => [str_repeat('x', 192)];
    }

    public function testCursorIsAuthenticatedAndBoundToActorLibraryAndCanonicalQuery(): void
    {
        $codec = new CatalogQueryCursorCodec(str_repeat('s', 32));
        $library = new LibraryId('library-a');
        $actor = new UserId('actor-a');
        $query = new CatalogQuery(
            filters: new CatalogFilters(
                authorIds: [new AuthorId('author-b'), new AuthorId('author-a')],
                seriesIds: [new SeriesId('series-a')],
                collections: CatalogCollectionFilter::in([new CollectionId('collection-a')])
            ),
            sort: CatalogQuerySort::Author,
            search: new CatalogSearchTerm('Title')
        );
        $cursor = $codec->encode($query, $library, $actor, new ItemId('item-a'));
        self::assertSame('item-a', $codec->decode($cursor, $query, $library, $actor)->value());

        foreach ([
            static fn () => $codec->decode($cursor, $query, new LibraryId('library-b'), $actor),
            static fn () => $codec->decode($cursor, $query, $library, new UserId('actor-b')),
            static fn () => $codec->decode($cursor, new CatalogQuery(
                filters: $query->filters(),
                sort: CatalogQuerySort::Title,
                search: $query->search()
            ), $library, $actor),
            static fn () => $codec->decode($cursor, new CatalogQuery(
                filters: new CatalogFilters(authorIds: [new AuthorId('author-c')]),
                sort: CatalogQuerySort::Author,
                search: $query->search()
            ), $library, $actor),
            static fn () => $codec->decode($cursor, new CatalogQuery(
                filters: $query->filters(),
                sort: $query->sort(),
                pageSize: new CatalogOverviewPageSize(25),
                search: $query->search()
            ), $library, $actor),
            static fn () => $codec->decode(new CatalogQueryCursor($cursor->opaqueValue() . 'x'), $query, $library, $actor),
            static fn () => $codec->decode(new CatalogQueryCursor('not-a-valid-cursor'), $query, $library, $actor),
        ] as $operation) {
            try {
                $operation();
                self::fail('An incompatible or manipulated cursor was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
}
