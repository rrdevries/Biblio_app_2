<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Metadata\Search\BibliographicSearchResultKind;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorMatchQuality;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorReference;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchProvider;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchResult;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchSourcePage;
use Biblio\Core\Application\Metadata\Search\BibliographicProviderEntityIdentity;
use Biblio\Core\Application\Metadata\Search\BibliographicSearchCursor;
use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchQuery;
use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchRequest;
use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchService;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSearchPage;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSearchProvider;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicSearchProvider;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicProviderIdentityRepository;
use Biblio\Core\Catalog\AuthorId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;

final class BibliographicSearchPersistenceTest extends PersistenceIntegrationTestCase
{
    public function testLocalAuthorPagesRankExactBeforeBroaderAtTheSource(): void
    {
        foreach ([
            ["author-anthony", "Anthony Stephen King"],
            ["author-exact", "Stephen King"],
            ["author-initial", "Stephen D. King"],
            ["author-hall", "Stephen King-Hall"],
        ] as [$authorId, $displayName]) {
            self::assertSame(1, $this->database->insert($this->tableNames->authors(), [
                "author_id" => $authorId,
                "display_name" => $displayName,
            ]));
        }

        $page = (new WpdbBibliographicSearchProvider(
            $this->database,
            $this->tableNames
        ))->searchAuthors(new BibliographicTextSearchQuery("stephen king"));

        self::assertSame("author-exact", $page->items()[0]->reference()->authorId()?->value());
        self::assertSame(BibliographicAuthorMatchQuality::Exact, $page->items()[0]->matchQuality());
        self::assertSame([
            "author-anthony",
            "author-initial",
            "author-hall",
        ], array_map(
            static fn ($item): ?string => $item->reference()->authorId()?->value(),
            array_slice($page->items(), 1)
        ));

        $whitespaceEquivalent = (new WpdbBibliographicSearchProvider(
            $this->database,
            $this->tableNames
        ))->searchAuthors(new BibliographicTextSearchQuery("STEPHEN   KING"));
        self::assertSame(
            "author-exact",
            $whitespaceEquivalent->items()[0]->reference()->authorId()?->value()
        );
        self::assertSame(
            BibliographicAuthorMatchQuality::Exact,
            $whitespaceEquivalent->items()[0]->matchQuality()
        );
    }

    public function testAuthorProviderMappingsAreReadInBoundedBatchesBothWays(): void
    {
        foreach (["author-one", "author-two"] as $authorId) {
            self::assertSame(1, $this->database->insert($this->tableNames->authors(), [
                "author_id" => $authorId,
                "display_name" => ucfirst(str_replace("-", " ", $authorId)),
            ]));
        }
        $repository = new WpdbBibliographicProviderIdentityRepository(
            $this->database,
            $this->tableNames
        );
        $repository->claimAuthor("open_library", "/authors/OL501A", new AuthorId("author-one"));
        $repository->claimAuthor("open_library", "/authors/OL502A", new AuthorId("author-two"));

        $beforeForward = $this->database->num_queries;
        $mapped = $repository->mappedAuthors("open_library", [
            "/authors/OL501A",
            "/authors/OL502A",
            "/authors/OL999A",
        ]);
        self::assertSame(1, $this->database->num_queries - $beforeForward);
        self::assertSame("author-one", $mapped["/authors/OL501A"]->value());
        self::assertSame("author-two", $mapped["/authors/OL502A"]->value());

        $beforeReverse = $this->database->num_queries;
        $claims = $repository->providerAuthorIdentities("open_library", [
            new AuthorId("author-one"),
            new AuthorId("author-two"),
        ]);
        self::assertSame(1, $this->database->num_queries - $beforeReverse);
        self::assertSame("/authors/OL501A", $claims["author-one"][0]->providerRecordId());
        self::assertSame("/authors/OL502A", $claims["author-two"][0]->providerRecordId());
    }

    public function testLocalCanonicalTieOrderRemainsStableAcrossUnicodePageBoundary(): void
    {
        $broaderNames = [
            "Alpha Author",
            "Beta Author",
            "Delta Author",
            "Epsilon Author",
            "Eta Author",
            "Gamma Author",
            "Iota Author",
            "Kappa Author",
            "Lambda Author",
            "Omega Author",
            "STRAẞE Author",
            "Strasse Author",
        ];
        foreach (["Author", ...$broaderNames] as $position => $displayName) {
            self::assertSame(1, $this->database->insert($this->tableNames->authors(), [
                "author_id" => "author-order-{$position}",
                "display_name" => $displayName,
            ]));
        }
        $provider = new WpdbBibliographicSearchProvider($this->database, $this->tableNames);
        $query = new BibliographicTextSearchQuery("author");

        $first = $provider->searchAuthors($query, 0, 10);
        self::assertSame(10, $first->nextOffset());
        $second = $provider->searchAuthors($query, $first->nextOffset() ?? 0, 10);
        self::assertNull($second->nextOffset());
        $actual = array_map(
            static fn ($item): string => $item->displayName(),
            [...$first->items(), ...$second->items()]
        );
        sort($broaderNames, SORT_STRING);

        self::assertSame(["Author", ...$broaderNames], $actual);
        self::assertCount(count(array_unique($actual)), $actual);
    }

    public function testStephenKingCompositionKeepsCanonicalAndOnlyUnmappedCandidates(): void
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
        $identities = new WpdbBibliographicProviderIdentityRepository(
            $this->database,
            $this->tableNames
        );
        $identities->claimAuthor(
            "open_library",
            "/authors/OL19981A",
            new AuthorId("author-stephen-king")
        );
        $query = new BibliographicTextSearchQuery("stephen king");
        $external = new IntegrationExternalSearchProvider([
            $this->externalAuthor($query, "/authors/OL19981A", "Stephen King", 0),
            $this->externalAuthor($query, "/authors/OL60001A", "Stephen King", 1),
            $this->externalAuthor($query, "/authors/OL60002A", "Stephen D. King", 2),
            $this->externalAuthor($query, "/authors/OL60003A", "Anthony Stephen King", 3),
        ]);
        $local = new WpdbBibliographicSearchProvider($this->database, $this->tableNames);
        $tables = [
            $this->tableNames->authors(),
            $this->tableNames->works(),
            $this->tableNames->workContributors(),
            $this->tableNames->bibliographicProviderIdentities(),
        ];
        $before = $this->rowCounts($tables);

        $result = (new BibliographicTextSearchService(
            new ControllableAuthenticatedUser(new UserId("search-integration-user")),
            $local,
            $local,
            $external,
            $external,
            $identities,
            $identities
        ))->search(new BibliographicTextSearchRequest($query));

        self::assertSame([
            "author-stephen-king",
            "/authors/OL60001A",
            "/authors/OL60002A",
            "/authors/OL60003A",
        ], array_map(static fn (BibliographicAuthorSearchResult $item): string =>
            $item->reference()->authorId()?->value()
                ?? $item->reference()->providerIdentity()?->providerRecordId()
                ?? "",
            $result->authors()->items()
        ));
        self::assertSame(
            "/authors/OL19981A",
            $result->authors()->items()[0]->reference()->providerIdentity()?->providerRecordId()
        );
        self::assertSame("work-it", $result->works()->items()[0]->reference()->workId()?->value());
        self::assertSame([9], $external->authorLimits);
        self::assertSame($before, $this->rowCounts($tables));
    }

    private function externalAuthor(
        BibliographicTextSearchQuery $query,
        string $recordId,
        string $name,
        int $order
    ): BibliographicAuthorSearchResult {
        return new BibliographicAuthorSearchResult(
            BibliographicAuthorReference::external(
                BibliographicProviderEntityIdentity::author("open_library", $recordId)
            ),
            $name,
            $order,
            $query
        );
    }

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
        $second = $provider->searchAuthors($query, $first->nextOffset() ?? 0);

        self::assertCount(10, $first->items());
        self::assertNotNull($first->nextOffset());
        self::assertSame("search-author-01", $first->items()[0]->reference()->authorId()?->value());
        self::assertCount(2, $second->items());
        self::assertNull($second->nextOffset());
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

final class IntegrationExternalSearchProvider implements
    BibliographicAuthorSearchProvider,
    BibliographicWorkSearchProvider
{
    /** @var list<int> */ public array $authorLimits = [];

    /** @param list<BibliographicAuthorSearchResult> $authors */
    public function __construct(private array $authors) {}

    public function key(): string { return "open_library"; }

    public function searchAuthors(
        BibliographicTextSearchQuery $query,
        int $offset = 0,
        int $limit = BibliographicTextSearchService::PAGE_SIZE
    ): BibliographicAuthorSearchSourcePage {
        $this->authorLimits[] = $limit;
        return new BibliographicAuthorSearchSourcePage(
            array_slice($this->authors, $offset, $limit),
            null
        );
    }

    public function searchWorks(
        BibliographicTextSearchQuery $query,
        ?BibliographicSearchCursor $cursor = null
    ): BibliographicWorkSearchPage {
        return new BibliographicWorkSearchPage($query, [], null);
    }
}
