<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Catalog\Query\{CatalogArchiveScope,CatalogQueryCursor,CatalogQueryItem,CatalogQueryPage,CatalogQuerySeriesContext,CatalogQuerySort};
use Biblio\Core\Application\Catalog\Read\CatalogOverviewPageSize;
use Biblio\Core\Application\Library\{LibraryCapabilities,LibraryContextView};
use Biblio\Core\Catalog\Classification\{LibraryBookTypeId,LibraryCatalogSelection,LibraryGenreId,LibrarySubjectId};
use Biblio\Core\Catalog\{Author,AuthorId,EditionId,ItemId,ItemStatus,LibraryLocation,LocationId,Series,SeriesId,SeriesPosition,WorkId};
use Biblio\Core\Collections\CollectionId;
use Biblio\Core\Infrastructure\WordPress\Rest\{CatalogCursorCodec,PrivateNoteCursorCodec,ReadingHistoryCursorCodec,RestCatalogQueryParser,RestRequestException,RestResponseSerializer};
use Biblio\Core\Library\{LibraryId,LibraryName,LibraryStatus,LibraryType};
use Biblio\Core\Reading\PersonalWorkReadingStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RestCatalogQueryTransportTest extends TestCase
{
    public function testParserBuildsTheCompleteTypedCatalogQuery(): void
    {
        $query = (new RestCatalogQueryParser())->parse([
            'search' => '  Renée  ',
            'reading_statuses' => ['reading', 'read'],
            'author_ids' => ['author-a', 'author-b'],
            'series_ids' => ['series-a'],
            'location_ids' => ['location-a'],
            'book_type_ids' => ['book-a'],
            'genre_ids' => ['genre-a'],
            'subject_ids' => ['subject-a'],
            'collection_ids' => ['collection-a'],
            'without_collection' => 'false',
            'sort' => 'series',
            'page_size' => '100',
            'archive_scope' => 'active_and_archived',
            'cursor' => 'opaque-cursor',
        ]);

        self::assertSame('Renée', $query->search()?->value());
        self::assertSame(CatalogQuerySort::Series, $query->sort());
        self::assertSame(100, $query->pageSize()->value());
        self::assertSame(CatalogArchiveScope::ActiveAndArchived, $query->archiveScope());
        self::assertSame('opaque-cursor', $query->cursor()?->opaqueValue());
        self::assertSame(['reading', 'read'], array_map(
            static fn (PersonalWorkReadingStatus $status): string => $status->value,
            $query->filters()->readingStatuses()
        ));
        self::assertSame(['author-a', 'author-b'], $this->values($query->filters()->authorIds()));
        self::assertSame(['series-a'], $this->values($query->filters()->seriesIds()));
        self::assertSame(['location-a'], $this->values($query->filters()->locationIds()));
        self::assertSame(['book-a'], $this->values($query->filters()->bookTypeIds()));
        self::assertSame(['genre-a'], $this->values($query->filters()->genreIds()));
        self::assertSame(['subject-a'], $this->values($query->filters()->subjectIds()));
        self::assertSame(['collection-a'], $this->values(
            $query->filters()->collections()->collectionIds()
        ));
        self::assertFalse($query->filters()->collections()->isWithoutCollection());

        $defaults = (new RestCatalogQueryParser())->parse([]);
        self::assertNull($defaults->search());
        self::assertSame(CatalogQuerySort::Title, $defaults->sort());
        self::assertSame(CatalogOverviewPageSize::DEFAULT, $defaults->pageSize()->value());
        self::assertSame(CatalogArchiveScope::ActiveOnly, $defaults->archiveScope());
        self::assertNull($defaults->cursor());

        $without = (new RestCatalogQueryParser())->parse([
            'without_collection' => 'true',
        ]);
        self::assertTrue($without->filters()->collections()->isWithoutCollection());
    }

    #[DataProvider('invalidRequests')]
    public function testParserRejectsUnknownMalformedDuplicateAndExcessiveInput(
        array $parameters,
        string $code
    ): void {
        try {
            (new RestCatalogQueryParser())->parse($parameters);
            self::fail('Invalid catalog request was accepted.');
        } catch (RestRequestException $exception) {
            self::assertSame($code, $exception->errorCode());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidRequests(): iterable
    {
        yield 'unknown parameter' => [['user_id' => '7'], 'biblio_unknown_request_fields'];
        yield 'empty search' => [['search' => ' '], 'biblio_invalid_field_syntax'];
        yield 'short search' => [['search' => 'x'], 'biblio_invalid_field_syntax'];
        yield 'long search' => [[
            'search' => str_repeat('x', 192),
        ], 'biblio_invalid_field_syntax'];
        yield 'scalar list' => [['author_ids' => 'author-a'], 'biblio_invalid_field_type'];
        yield 'empty list' => [['author_ids' => []], 'biblio_invalid_field_syntax'];
        yield 'duplicate list value' => [[
            'author_ids' => ['author-a', 'author-a'],
        ], 'biblio_invalid_field_syntax'];
        yield 'excessive list' => [[
            'author_ids' => array_map(
                static fn (int $index): string => "author-{$index}",
                range(0, RestCatalogQueryParser::MAXIMUM_FILTER_VALUES)
            ),
        ], 'biblio_invalid_field_syntax'];
        yield 'invalid reading status' => [[
            'reading_statuses' => ['unknown'],
        ], 'biblio_invalid_field_syntax'];
        yield 'Collection conflict' => [[
            'collection_ids' => ['collection-a'],
            'without_collection' => 'true',
        ], 'biblio_invalid_field_syntax'];
        yield 'invalid boolean' => [[
            'without_collection' => '1',
        ], 'biblio_invalid_field_type'];
        yield 'invalid sort' => [['sort' => 'newest'], 'biblio_invalid_field_syntax'];
        yield 'duplicate scalar sort' => [[
            'sort' => ['title', 'author'],
        ], 'biblio_invalid_field_type'];
        yield 'page size below minimum' => [['page_size' => '0'], 'biblio_invalid_field_syntax'];
        yield 'page size above maximum' => [['page_size' => '101'], 'biblio_invalid_field_syntax'];
        yield 'page size wrong format' => [['page_size' => '1.0'], 'biblio_invalid_field_type'];
        yield 'invalid archive scope' => [[
            'archive_scope' => 'archived_only',
        ], 'biblio_invalid_field_syntax'];
        yield 'empty cursor' => [['cursor' => ''], 'biblio_invalid_field_syntax'];
    }

    public function testSerializerAllowlistUsesOnlyLoadedTypedPageData(): void
    {
        $serializer = new RestResponseSerializer(
            new CatalogCursorCodec(),
            new ReadingHistoryCursorCodec(),
            new PrivateNoteCursorCodec()
        );
        $library = new LibraryContextView(
            new LibraryId('library-a'),
            new LibraryName('Mijn bibliotheek'),
            LibraryType::PrivateLibrary,
            LibraryStatus::Active,
            true,
            new LibraryCapabilities(true, false, false, false, false, false, false, false, true, false)
        );
        $item = new CatalogQueryItem(
            new ItemId('item-a'),
            new WorkId('work-a'),
            new EditionId('edition-a'),
            'Titel',
            ItemStatus::Archived,
            'INV-1',
            [new Author(new AuthorId('author-a'), 'Auteur')],
            [new CatalogQuerySeriesContext(
                new Series(new SeriesId('series-a'), 'Serie'),
                SeriesPosition::known('2')
            )],
            new LibraryLocation(new LocationId('location-a'), new LibraryId('library-a'), 'Kast'),
            new LibraryCatalogSelection(
                new LibraryBookTypeId('book-a'),
                [new LibraryGenreId('genre-a')],
                [new LibrarySubjectId('subject-a')]
            ),
            [new CollectionId('collection-a')],
            PersonalWorkReadingStatus::Read,
            'Deeltitel'
        );

        $data = $serializer->catalogQuery(new CatalogQueryPage(
            $library,
            [$item],
            new CatalogQueryCursor('opaque-next')
        ));

        self::assertSame(['library', 'items', 'next_cursor'], array_keys($data));
        self::assertSame('opaque-next', $data['next_cursor']);
        self::assertSame([
            'item_id', 'work_id', 'edition_id', 'title', 'item_status',
            'inventory_number', 'authors', 'series', 'location',
            'classification', 'collection_ids', 'reading_status',
            'contained_match_title',
        ], array_keys($data['items'][0]));
        self::assertSame('archived', $data['items'][0]['item_status']);
        self::assertSame([
            'author_id' => 'author-a',
            'display_name' => 'Auteur',
        ], $data['items'][0]['authors'][0]);
        self::assertSame('2', $data['items'][0]['series'][0]['position']);
        self::assertSame([
            'book_type_id' => 'book-a',
            'genre_ids' => ['genre-a'],
            'subject_ids' => ['subject-a'],
        ], $data['items'][0]['classification']);
        self::assertArrayNotHasKey('user_id', $data['items'][0]);
        self::assertArrayNotHasKey('notes', $data['items'][0]);
        self::assertArrayNotHasKey('version', $data['items'][0]);
        self::assertSame([
            'library' => $data['library'],
            'items' => [],
            'next_cursor' => null,
        ], $serializer->catalogQuery(new CatalogQueryPage($library, [], null)));
    }

    /**
     * @param list<AuthorId|SeriesId|LocationId|LibraryBookTypeId|LibraryGenreId|LibrarySubjectId|CollectionId> $values
     * @return list<string>
     */
    private function values(array $values): array
    {
        return array_map(
            static fn (
                AuthorId|SeriesId|LocationId|LibraryBookTypeId|LibraryGenreId|LibrarySubjectId|CollectionId $value
            ): string => $value->value(),
            $values
        );
    }
}
