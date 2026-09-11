<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchQuery;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicSearchProvider;

final class BibliographicSearchPersistenceTest extends PersistenceIntegrationTestCase
{
    public function testLocalAuthorsAreDeterministicallyPageableWithoutHiddenTopTen(): void
    {
        for ($position = 1; $position <= 12; $position++) {
            self::assertSame(1, $this->database->insert($this->tableNames->authors(), [
                "author_id" => sprintf("search-author-%02d", $position),
                "display_name" => sprintf("Discovery Author %02d", $position),
            ]));
        }
        self::assertSame(1, $this->database->insert($this->tableNames->authors(), [
            "author_id" => "search-author-unmatched",
            "display_name" => "Someone Else",
        ]));
        $provider = new WpdbBibliographicSearchProvider($this->database, $this->tableNames);
        $query = new BibliographicTextSearchQuery("Discovery Author");

        $first = $provider->searchAuthors($query);
        $second = $provider->searchAuthors($query, $first->nextCursor());

        self::assertCount(10, $first->items());
        self::assertNotNull($first->nextCursor());
        self::assertSame("search-author-01", $first->items()[0]->reference()->authorId()?->value());
        self::assertCount(2, $second->items());
        self::assertNull($second->nextCursor());
        self::assertSame("search-author-12", $second->items()[1]->reference()->authorId()?->value());
    }

    public function testLocalWorksMatchTitleAndLinkedAuthorTokensAndBatchProjectRelations(): void
    {
        self::assertSame(1, $this->database->insert($this->tableNames->works(), [
            "work_id" => "search-work",
            "work_title" => "The Dispossessed",
            "work_title_status" => "librarian_confirmed",
        ]));
        self::assertSame(1, $this->database->insert($this->tableNames->authors(), [
            "author_id" => "search-author",
            "display_name" => "Ursula K. Le Guin",
        ]));
        self::assertSame(1, $this->database->insert($this->tableNames->workContributors(), [
            "work_id" => "search-work",
            "author_id" => "search-author",
            "contributor_role" => "author",
            "contributor_position" => 1,
        ]));
        self::assertSame(1, $this->database->insert($this->tableNames->series(), [
            "series_id" => "search-series",
            "display_name" => "Hainish Cycle",
        ]));
        self::assertSame(1, $this->database->insert($this->tableNames->workSeries(), [
            "work_id" => "search-work",
            "series_id" => "search-series",
            "series_position" => "6",
        ]));
        $provider = new WpdbBibliographicSearchProvider($this->database, $this->tableNames);

        $page = $provider->searchWorks(
            new BibliographicTextSearchQuery("Dispossessed Ursula")
        );

        self::assertCount(1, $page->items());
        $work = $page->items()[0];
        self::assertSame("search-work", $work->reference()->workId()?->value());
        self::assertSame("The Dispossessed", $work->title());
        self::assertSame("search-author", $work->authors()[0]->authorId()?->value());
        self::assertSame("Ursula K. Le Guin", $work->authors()[0]->displayName());
        self::assertSame("search-series", $work->series()[0]->seriesId()?->value());
        self::assertSame("6", $work->series()[0]->position()?->value());
        self::assertNull($page->nextCursor());
    }
}
