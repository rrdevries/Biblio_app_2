<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Catalog\Query\{CatalogCollectionFilter,CatalogFilters,CatalogQuery,CatalogQuerySort,CatalogSearchTerm};
use Biblio\Core\Application\Catalog\Read\CatalogOverviewPageSize;
use Biblio\Core\Catalog\Classification\LibraryGenreId;
use Biblio\Core\Catalog\{AuthorId,ItemId,LocationId,SeriesId};
use Biblio\Core\Collections\CollectionId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbCatalogQueryRepository;
use Biblio\Core\Library\LibraryId;
use PHPUnit\Framework\Attributes\DataProvider;

final class CatalogQueryPerformanceTest extends PersistenceIntegrationTestCase
{
    #[DataProvider('datasetSizes')]
    public function testRepresentativePlansRemainBoundedAtCatalogScale(int $size): void
    {
        $this->seedPerformanceDataset($size);
        $repository = new WpdbCatalogQueryRepository($this->database, $this->tableNames);
        $library = new LibraryId('performance-library');
        $actor = new UserId('501');
        $pageSize = new CatalogOverviewPageSize(24);
        $scenarios = [
            'default' => new CatalogQuery(pageSize: $pageSize),
            'location' => new CatalogQuery(filters: new CatalogFilters(locationIds: [new LocationId('location-a')]), pageSize: $pageSize),
            'author_filter' => new CatalogQuery(filters: new CatalogFilters(authorIds: [new AuthorId('author-a')]), pageSize: $pageSize),
            'author_sort' => new CatalogQuery(sort: CatalogQuerySort::Author, pageSize: $pageSize),
            'genre_collection' => new CatalogQuery(filters: new CatalogFilters(
                genreIds: [new LibraryGenreId('genre-a')],
                collections: CatalogCollectionFilter::in([new CollectionId('collection-a')])
            ), pageSize: $pageSize),
            'series_sort' => new CatalogQuery(
                filters: new CatalogFilters(seriesIds: [new SeriesId('series-a')]),
                sort: CatalogQuerySort::Series,
                pageSize: $pageSize
            ),
            'search' => new CatalogQuery(search: new CatalogSearchTerm('Title 000'), pageSize: $pageSize),
        ];

        foreach ($scenarios as $name => $query) {
            $started = hrtime(true);
            $page = $repository->page($library, $actor, $query, null);
            $milliseconds = (hrtime(true) - $started) / 1_000_000;
            self::assertNotSame([], $page->records(), "{$name} returned no representative rows.");
            self::assertSame('', $this->database->last_error, "{$name} query failed.");
            $plan = $this->database->get_results('EXPLAIN ' . $this->database->last_query, ARRAY_A);
            self::assertNotSame([], $plan, "{$name} produced no query plan.");
            $itemPlan = array_values(array_filter(
                $plan,
                fn (array $row): bool => ($row['table'] ?? null) === 'i'
                    || ($row['key'] ?? null) === 'items_by_library_status_location'
            ));
            self::assertNotSame([], $itemPlan, "{$name} did not expose the tenant Item access path.");
            fwrite(STDOUT, sprintf(
                "\nCATALOG_PLAN size=%d scenario=%s ms=%.3f key=%s rows=%s extra=%s",
                $size,
                $name,
                $milliseconds,
                (string) ($itemPlan[0]['key'] ?? 'none'),
                (string) ($itemPlan[0]['rows'] ?? 'unknown'),
                (string) ($itemPlan[0]['Extra'] ?? '')
            ));
        }

        $first = $repository->page($library, $actor, new CatalogQuery(pageSize: $pageSize), null);
        $last = $first->records()[array_key_last($first->records())];
        $started = hrtime(true);
        $next = $repository->page($library, $actor, new CatalogQuery(pageSize: $pageSize), $last->itemId());
        $milliseconds = (hrtime(true) - $started) / 1_000_000;
        self::assertCount(24, $next->records());
        self::assertSame([], array_intersect(
            array_map(static fn ($row): string => $row->itemId()->value(), $first->records()),
            array_map(static fn ($row): string => $row->itemId()->value(), $next->records())
        ));
        $plan = $this->database->get_results('EXPLAIN ' . $this->database->last_query, ARRAY_A);
        $itemPlan = array_values(array_filter(
            $plan,
            fn (array $row): bool => ($row['table'] ?? null) === 'i'
                || ($row['key'] ?? null) === 'items_by_library_status_location'
        ));
        self::assertNotSame([], $itemPlan, 'The next page did not retain the tenant Item access path.');
        fwrite(STDOUT, sprintf(
            "\nCATALOG_PLAN size=%d scenario=next_page ms=%.3f key=%s rows=%s extra=%s",
            $size,
            $milliseconds,
            (string) ($itemPlan[0]['key'] ?? 'none'),
            (string) ($itemPlan[0]['rows'] ?? 'unknown'),
            (string) ($itemPlan[0]['Extra'] ?? '')
        ));
    }

    /** @return iterable<string,array{int}> */
    public static function datasetSizes(): iterable
    {
        yield 'one thousand' => [1000];
        yield 'ten thousand' => [10000];
    }

    private function seedPerformanceDataset(int $size): void
    {
        $this->database->insert($this->tableNames->libraries(), [
            'library_id' => 'performance-library', 'library_name' => 'Performance',
            'library_type' => 'private_library', 'library_status' => 'active',
        ]);
        $this->database->insert($this->tableNames->locations(), [
            'library_id' => 'performance-library', 'location_id' => 'location-a', 'display_name' => 'Location A',
        ]);
        $this->database->insert($this->tableNames->authors(), ['author_id' => 'author-a', 'display_name' => 'Author A']);
        $this->database->insert($this->tableNames->series(), ['series_id' => 'series-a', 'display_name' => 'Series A']);
        $this->database->insert($this->tableNames->libraryBookTypes(), [
            'library_id' => 'performance-library', 'book_type_id' => 'book-a', 'display_name' => 'Book',
            'normalized_name' => 'book', 'term_status' => 'active',
        ]);
        $this->database->insert($this->tableNames->libraryGenres(), [
            'library_id' => 'performance-library', 'genre_id' => 'genre-a', 'display_name' => 'Genre',
            'normalized_name' => 'genre', 'term_status' => 'active',
        ]);
        $this->database->insert($this->tableNames->collections(), [
            'library_id' => 'performance-library', 'collection_id' => 'collection-a',
            'collection_name' => 'Collection', 'normalized_name' => 'collection', 'collection_status' => 'active',
            'collection_position' => 1, 'collection_version' => 1,
            'created_at' => '2026-09-04 10:00:00.000000', 'updated_at' => '2026-09-04 10:00:00.000000',
        ]);

        for ($start = 1; $start <= $size; $start += 250) {
            $end = min($size, $start + 249);
            $works = $editions = $items = [];
            $contributors = $series = $contexts = $genres = $memberships = [];
            for ($number = $start; $number <= $end; ++$number) {
                $id = str_pad((string) $number, 5, '0', STR_PAD_LEFT);
                $works[] = "('perf-work-{$id}','Title {$id}')";
                $editions[] = "('perf-edition-{$id}','perf-work-{$id}',NULL,NULL,0)";
                $location = $number % 2 === 0 ? "'location-a'" : 'NULL';
                $items[] = "('perf-item-{$id}','performance-library','perf-edition-{$id}','active',NULL,{$location},1)";
                if ($number % 10 === 0) {
                    $contributors[] = "('perf-work-{$id}','author-a','author',1)";
                    $series[] = "('perf-work-{$id}','series-a',{$number})";
                    $contexts[] = "('performance-library','perf-work-{$id}','book-a',1)";
                    $genres[] = "('performance-library','perf-work-{$id}','genre-a')";
                    $memberships[] = "('performance-library','perf-membership-{$id}','collection-a','perf-item-{$id}','active',{$number},'2026-09-04 10:00:00.000000',NULL,NULL)";
                }
            }
            $this->insertSql($this->tableNames->works(), '(work_id,work_title)', $works);
            $this->insertSql($this->tableNames->editions(), '(edition_id,work_id,isbn_10,isbn_13,explicitly_no_isbn)', $editions);
            $this->insertSql($this->tableNames->items(), '(item_id,library_id,edition_id,item_status,inventory_number,location_id,item_version)', $items);
            $this->insertSql($this->tableNames->workContributors(), '(work_id,author_id,contributor_role,contributor_position)', $contributors);
            $this->insertSql($this->tableNames->workSeries(), '(work_id,series_id,series_position)', $series);
            $this->insertSql($this->tableNames->libraryCatalogContexts(), '(library_id,work_id,book_type_id,context_version)', $contexts);
            $this->insertSql($this->tableNames->libraryCatalogContextGenres(), '(library_id,work_id,genre_id)', $genres);
            $this->insertSql($this->tableNames->collectionMemberships(), '(library_id,membership_id,collection_id,item_id,membership_status,item_position,added_at,ended_at,end_reason)', $memberships);
        }
        self::assertSame($size, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->items()}` WHERE library_id='performance-library'"
        ));
    }

    /** @param list<string> $rows */
    private function insertSql(string $table, string $columns, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $result = $this->database->query("INSERT INTO `{$table}` {$columns} VALUES " . implode(',', $rows));
        self::assertSame(count($rows), $result, $this->database->last_error);
    }
}
