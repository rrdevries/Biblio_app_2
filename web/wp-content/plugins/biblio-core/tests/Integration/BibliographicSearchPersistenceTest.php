<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Metadata\Search\BibliographicSearchResultKind;
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
        $query = new BibliographicTextSearchQuery("discovery author");

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
            new BibliographicTextSearchQuery("dispossessed ursula")
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

    public function testLocalAuthorMatchingIsCaseInsensitiveAndReadOnly(): void
    {
        self::assertSame(1, $this->database->insert($this->tableNames->authors(), [
            "author_id" => "author-stephen-king",
            "display_name" => "Stephen King",
        ]));
        self::assertSame(1, $this->database->insert($this->tableNames->works(), [
            "work_id" => "work-it",
            "work_title" => "It",
            "work_title_status" => "librarian_confirmed",
        ]));
        self::assertSame(1, $this->database->insert($this->tableNames->workContributors(), [
            "work_id" => "work-it",
            "author_id" => "author-stephen-king",
            "contributor_role" => "author",
            "contributor_position" => 1,
        ]));
        $provider = new WpdbBibliographicSearchProvider($this->database, $this->tableNames);
        $tables = [
            $this->tableNames->authors(),
            $this->tableNames->works(),
            $this->tableNames->workContributors(),
            $this->tableNames->bibliographicProviderIdentities(),
            $this->tableNames->bibliographicDiscoverySnapshots(),
            $this->tableNames->bibliographicDiscoveryCandidates(),
        ];
        $before = $this->rowCounts($tables);

        foreach (["Stephen King", "stephen king", "STEPHEN KING", "sTePhEn KiNg"] as $input) {
            $page = $provider->searchAuthors(new BibliographicTextSearchQuery($input));

            self::assertCount(1, $page->items(), $input);
            self::assertSame(
                BibliographicSearchResultKind::LocalCanonical,
                $page->items()[0]->reference()->kind(),
                $input
            );
            self::assertSame(
                "author-stephen-king",
                $page->items()[0]->reference()->authorId()?->value(),
                $input
            );
            self::assertSame("Stephen King", $page->items()[0]->displayName(), $input);
        }

        $workPage = $provider->searchWorks(
            new BibliographicTextSearchQuery("stephen king")
        );
        self::assertCount(1, $workPage->items());
        self::assertSame("work-it", $workPage->items()[0]->reference()->workId()?->value());
        self::assertSame("It", $workPage->items()[0]->title());
        self::assertSame([], $provider->searchAuthors(
            new BibliographicTextSearchQuery("stephan king")
        )->items());
        self::assertSame($before, $this->rowCounts($tables));
    }

    public function testCaseInsensitiveMatchingPreservesWildcardAccentAndPunctuationSemantics(): void
    {
        foreach ([
            ["author-literal", "100% Real_Name"],
            ["author-wildcard-lookalike", "1000 RealXName"],
            ["author-accented", "José Saramago"],
            ["author-punctuated", "Flannery O'Connor"],
        ] as [$authorId, $displayName]) {
            self::assertSame(1, $this->database->insert($this->tableNames->authors(), [
                "author_id" => $authorId,
                "display_name" => $displayName,
            ]));
        }
        $provider = new WpdbBibliographicSearchProvider($this->database, $this->tableNames);

        $literal = $provider->searchAuthors(
            new BibliographicTextSearchQuery("100% real_name")
        );

        self::assertCount(1, $literal->items());
        self::assertSame(
            "author-literal",
            $literal->items()[0]->reference()->authorId()?->value()
        );
        self::assertSame([], $provider->searchAuthors(
            new BibliographicTextSearchQuery("JOSE SARAMAGO")
        )->items());
        self::assertSame([], $provider->searchAuthors(
            new BibliographicTextSearchQuery("FLANNERY OCONNOR")
        )->items());
    }

    /**
     * @param list<string> $tables
     * @return array<string,int>
     */
    private function rowCounts(array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$table}`"
            );
        }
        return $counts;
    }
}
