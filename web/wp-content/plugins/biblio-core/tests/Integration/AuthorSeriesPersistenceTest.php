<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Catalog\Read\BibliographicRelationshipQueryService;
use Biblio\Core\Catalog\{Author,AuthorId,CatalogRecordAlreadyExists,ContributorPosition,ContributorRole,Series,SeriesId,SeriesPosition,Work,WorkContributor,WorkId,WorkSeriesMembership};
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\Persistence\WordPress\{WpdbAuthorRepository,WpdbSeriesRepository,WpdbWorkRepository};

final class AuthorSeriesPersistenceTest extends PersistenceIntegrationTestCase
{
    public function testAuthorAndSeriesNamesRoundTripAndCorrectByStableId(): void
    {
        $authors = new WpdbAuthorRepository($this->database, $this->tableNames);
        $series = new WpdbSeriesRepository($this->database, $this->tableNames);
        $authors->save(new Author(new AuthorId("author-1"), "Ursula Le Guin"));
        $series->save(new Series(new SeriesId("series-1"), "Earthsea"));
        $authors->save(new Author(new AuthorId("author-1"), "Ursula K. Le Guin"));
        $series->save(new Series(new SeriesId("series-1"), "The Earthsea Cycle"));

        self::assertSame("Ursula K. Le Guin", $authors->find(new AuthorId("author-1"))?->displayName());
        self::assertSame("The Earthsea Cycle", $series->find(new SeriesId("series-1"))?->displayName());
        self::assertNull($authors->find(new AuthorId("missing-author")));
        self::assertNull($series->find(new SeriesId("missing-series")));

        $authors->save(new Author(new AuthorId("author-2"), "Ursula K. Le Guin"));
        $series->save(new Series(new SeriesId("series-2"), "The Earthsea Cycle"));
        self::assertCount(2, $authors->findMany([
            new AuthorId("author-1"),
            new AuthorId("author-2"),
        ]));
        self::assertCount(2, $series->findMany([
            new SeriesId("series-1"),
            new SeriesId("series-2"),
        ]));
    }

    public function testTypedRelationshipsAndOrderingRoundTripInBulk(): void
    {
        [$authors, $series] = $this->repositories();
        $this->seedWorks("work-1", "work-2");
        $authors->save(new Author(new AuthorId("author-1"), "Primary"));
        $authors->save(new Author(new AuthorId("author-2"), "Co-author"));
        $series->save(new Series(new SeriesId("series-1"), "Saga"));
        $authors->addContributor(new WorkContributor(new WorkId("work-1"), new AuthorId("author-2"), ContributorRole::CoAuthor, new ContributorPosition(2)));
        $authors->addContributor(new WorkContributor(new WorkId("work-1"), new AuthorId("author-1"), ContributorRole::Author, new ContributorPosition(1)));
        $authors->addContributor(new WorkContributor(new WorkId("work-2"), new AuthorId("author-1"), ContributorRole::Author, new ContributorPosition(1)));
        $series->addMembership(new WorkSeriesMembership(new WorkId("work-1"), new SeriesId("series-1"), SeriesPosition::known("1.5")));
        $series->addMembership(new WorkSeriesMembership(new WorkId("work-2"), new SeriesId("series-1"), SeriesPosition::unknown()));

        $query = new BibliographicRelationshipQueryService($authors, $series);
        $queriesBefore = $this->database->num_queries;
        $authorNames = $query->authors([
            new AuthorId("author-1"),
            new AuthorId("author-2"),
        ]);
        self::assertSame(1, $this->database->num_queries - $queriesBefore);
        self::assertSame("Primary", $authorNames["author-1"]->displayName());
        $queriesBefore = $this->database->num_queries;
        $seriesNames = $query->series([new SeriesId("series-1")]);
        self::assertSame(1, $this->database->num_queries - $queriesBefore);
        self::assertSame("Saga", $seriesNames["series-1"]->displayName());

        $queriesBefore = $this->database->num_queries;
        $contributors = $query->contributorsForWorks([new WorkId("work-1"), new WorkId("work-2"), new WorkId("work-empty")]);
        self::assertSame(1, $this->database->num_queries - $queriesBefore);
        self::assertSame(["author-1", "author-2"], array_map(static fn ($value) => $value->authorId()->value(), $contributors["work-1"]));
        $queriesBefore = $this->database->num_queries;
        $forAuthor = $query->workIdsForAuthors([new AuthorId("author-1")]);
        self::assertSame(1, $this->database->num_queries - $queriesBefore);
        self::assertSame(["work-1", "work-2"], array_map(static fn (WorkId $value) => $value->value(), $forAuthor["author-1"]));
        self::assertSame([], $contributors["work-empty"]);

        $queriesBefore = $this->database->num_queries;
        $forSeries = $query->worksForSeries([new SeriesId("series-1")])["series-1"];
        self::assertSame(1, $this->database->num_queries - $queriesBefore);
        self::assertSame(["work-1", "work-2"], array_map(static fn ($value) => $value->workId()->value(), $forSeries));
        self::assertSame("1.5", $forSeries[0]->position()->value());
        self::assertNull($forSeries[1]->position()->value());
        $queriesBefore = $this->database->num_queries;
        $forWork = $query->seriesForWorks([new WorkId("work-1")]);
        self::assertSame(1, $this->database->num_queries - $queriesBefore);
        self::assertSame("series-1", $forWork["work-1"][0]->seriesId()->value());
    }

    public function testDatabaseRejectsDuplicateAndDanglingRelationships(): void
    {
        [$authors, $series] = $this->repositories();
        $this->seedWorks("work-1");
        $authors->save(new Author(new AuthorId("author-1"), "Author"));
        $authors->save(new Author(new AuthorId("author-2"), "Second author"));
        $series->save(new Series(new SeriesId("series-1"), "Series"));
        $contributor = new WorkContributor(new WorkId("work-1"), new AuthorId("author-1"), ContributorRole::Author, new ContributorPosition(1));
        $membership = new WorkSeriesMembership(new WorkId("work-1"), new SeriesId("series-1"), SeriesPosition::unknown());
        $authors->addContributor($contributor);
        $series->addMembership($membership);

        foreach ([
            static fn () => $authors->addContributor($contributor),
            static fn () => $authors->addContributor(new WorkContributor(new WorkId("work-1"), new AuthorId("author-2"), ContributorRole::CoAuthor, new ContributorPosition(1))),
            static fn () => $series->addMembership($membership),
        ] as $duplicate) {
            try {
                $duplicate();
                self::fail("Duplicate relationship was accepted.");
            } catch (CatalogRecordAlreadyExists) {
                self::addToAssertionCount(1);
            }
        }

        foreach ([
            static fn () => $authors->addContributor(new WorkContributor(new WorkId("missing-work"), new AuthorId("author-1"), ContributorRole::Author, new ContributorPosition(2))),
            static fn () => $authors->addContributor(new WorkContributor(new WorkId("work-1"), new AuthorId("missing-author"), ContributorRole::CoAuthor, new ContributorPosition(2))),
            static fn () => $series->addMembership(new WorkSeriesMembership(new WorkId("missing-work"), new SeriesId("series-1"), SeriesPosition::unknown())),
            static fn () => $series->addMembership(new WorkSeriesMembership(new WorkId("work-1"), new SeriesId("missing-series"), SeriesPosition::unknown())),
        ] as $dangling) {
            try {
                $dangling();
                self::fail("Dangling relationship was accepted.");
            } catch (PersistenceException) {
                self::addToAssertionCount(1);
            }
        }

        $previous = $this->database->suppress_errors(true);
        try {
            self::assertFalse($this->database->delete($this->tableNames->works(), ["work_id" => "work-1"]));
            self::assertFalse($this->database->delete($this->tableNames->authors(), ["author_id" => "author-1"]));
            self::assertFalse($this->database->delete($this->tableNames->series(), ["series_id" => "series-1"]));
        } finally {
            $this->database->suppress_errors($previous);
        }
    }

    public function testCentralRelationshipsContainNoLibraryOrItemAccessPath(): void
    {
        foreach ([$this->tableNames->authors(), $this->tableNames->workContributors(), $this->tableNames->series(), $this->tableNames->workSeries()] as $table) {
            $columns = $this->database->get_col($this->database->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
                DB_NAME,
                $table
            ));
            self::assertNotContains("library_id", $columns);
            self::assertNotContains("item_id", $columns);
            self::assertNotContains("user_id", $columns);
        }
    }

    /** @return array{WpdbAuthorRepository, WpdbSeriesRepository} */
    private function repositories(): array
    {
        return [
            new WpdbAuthorRepository($this->database, $this->tableNames),
            new WpdbSeriesRepository($this->database, $this->tableNames),
        ];
    }

    private function seedWorks(string ...$ids): void
    {
        $repository = new WpdbWorkRepository($this->database, $this->tableNames);
        foreach ($ids as $id) {
            $repository->add(new Work(new WorkId($id), "Title {$id}"));
        }
    }
}
