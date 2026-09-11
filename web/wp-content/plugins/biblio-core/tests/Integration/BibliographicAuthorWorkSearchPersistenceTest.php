<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Metadata\Search\{
    BibliographicAuthorReference,
    BibliographicAuthorWorkSearchService
};
use Biblio\Core\Catalog\AuthorId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    WpdbBibliographicAuthorWorkSearchProvider,
    WpdbBibliographicProviderIdentityRepository
};
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;

final class BibliographicAuthorWorkSearchPersistenceTest extends PersistenceIntegrationTestCase
{
    public function testExactCanonicalAuthorIsDeterministicallyPageableAndBatchProjected(): void
    {
        $this->insertAuthor("author-selected", "Selected Author");
        $this->insertAuthor("author-co", "Co Author");
        $this->insertAuthor("author-other", "Other Author");
        for ($position = 1; $position <= 12; $position++) {
            $workId = sprintf("author-work-%02d", $position);
            $this->insertWork($workId, sprintf("Author Work %02d", $position));
            $this->insertContributor($workId, "author-selected", $position === 1 ? "co_author" : "author", 1);
            if ($position === 1) {
                $this->insertContributor($workId, "author-co", "author", 2);
                self::assertSame(1, $this->database->insert($this->tableNames->series(), [
                    "series_id" => "author-series",
                    "display_name" => "Author Series",
                ]));
                self::assertSame(1, $this->database->insert($this->tableNames->workSeries(), [
                    "work_id" => $workId,
                    "series_id" => "author-series",
                    "series_position" => "1",
                ]));
            }
        }
        $this->insertWork("other-work", "Unrelated");
        $this->insertContributor("other-work", "author-other", "author", 1);
        $provider = new WpdbBibliographicAuthorWorkSearchProvider(
            $this->database,
            $this->tableNames
        );
        $author = BibliographicAuthorReference::canonical(new AuthorId("author-selected"));

        $before = $this->database->num_queries;
        $first = $provider->searchWorksForAuthor($author, 0, 10);
        $queryCount = $this->database->num_queries - $before;
        $second = $provider->searchWorksForAuthor(
            $author,
            $first->nextOffset() ?? self::fail("Expected local continuation."),
            10
        );

        self::assertSame(4, $queryCount, "Author existence, Works, Authors and Series must remain bounded.");
        self::assertCount(10, $first->items());
        self::assertSame(10, $first->nextOffset());
        self::assertCount(2, $second->items());
        self::assertNull($second->nextOffset());
        self::assertSame("author-work-01", $first->items()[0]->reference()->workId()?->value());
        self::assertSame(
            ["Selected Author", "Co Author"],
            array_map(static fn ($author): string => $author->displayName(), $first->items()[0]->authors())
        );
        self::assertSame("Author Series", $first->items()[0]->series()[0]->displayName());
        self::assertNotContains(
            "other-work",
            array_map(static fn ($item): ?string => $item->reference()->workId()?->value(), [
                ...$first->items(),
                ...$second->items(),
            ])
        );
    }

    public function testUnsupportedContributorRoleCannotCreateFalseMembership(): void
    {
        $this->insertAuthor("author-selected", "Selected Author");
        $this->insertWork("translator-work", "Translator Work");

        $previous = $this->database->suppress_errors(true);
        try {
            $inserted = $this->database->insert($this->tableNames->workContributors(), [
                "work_id" => "translator-work",
                "author_id" => "author-selected",
                "contributor_role" => "translator",
                "contributor_position" => 1,
            ]);
        } finally {
            $this->database->suppress_errors($previous);
        }
        self::assertFalse($inserted);

        $page = (new WpdbBibliographicAuthorWorkSearchProvider(
            $this->database,
            $this->tableNames
        ))->searchWorksForAuthor(
            BibliographicAuthorReference::canonical(new AuthorId("author-selected")),
            0,
            10
        );
        self::assertSame([], $page->items());
    }

    public function testUnknownCanonicalAuthorFailsClosed(): void
    {
        $this->expectException(\Biblio\Core\Exception\ValidationException::class);
        (new WpdbBibliographicAuthorWorkSearchProvider(
            $this->database,
            $this->tableNames
        ))->searchWorksForAuthor(
            BibliographicAuthorReference::canonical(new AuthorId("missing-author")),
            0,
            10
        );
    }

    public function testProviderMappingDedupLookupIsExactAuthorScopedAndBatched(): void
    {
        $this->insertAuthor("author-selected", "Selected Author");
        $this->insertAuthor("author-other", "Other Author");
        $this->insertWork("mapped-selected", "Mapped Selected");
        $this->insertWork("mapped-other", "Mapped Other");
        $this->insertContributor("mapped-selected", "author-selected", "author", 1);
        $this->insertContributor("mapped-other", "author-other", "author", 1);
        foreach ([
            ["/works/OL40W", "mapped-selected"],
            ["/works/OL41W", "mapped-other"],
        ] as [$recordId, $workId]) {
            self::assertSame(1, $this->database->insert(
                $this->tableNames->bibliographicProviderIdentities(),
                [
                    "provider_key" => "open_library",
                    "source_entity_type" => "work",
                    "provider_record_id" => $recordId,
                    "target_type" => "work",
                    "work_id" => $workId,
                    "edition_id" => null,
                ]
            ));
        }
        $repository = new WpdbBibliographicProviderIdentityRepository(
            $this->database,
            $this->tableNames
        );

        $before = $this->database->num_queries;
        $mapped = $repository->mappedWorksForAuthor(
            new AuthorId("author-selected"),
            "open_library",
            ["/works/OL40W", "/works/OL41W"]
        );

        self::assertSame(1, $this->database->num_queries - $before);
        self::assertSame(["/works/OL40W"], array_keys($mapped));
        self::assertSame("mapped-selected", $mapped["/works/OL40W"]->value());
    }

    public function testProductionCompositionExposesSharedApplicationService(): void
    {
        self::assertInstanceOf(
            BibliographicAuthorWorkSearchService::class,
            (new ProductionComposition($this->database))->application()
                ->bibliographicAuthorWorkSearch()
        );
    }

    private function insertAuthor(string $id, string $name): void
    {
        self::assertSame(1, $this->database->insert($this->tableNames->authors(), [
            "author_id" => $id,
            "display_name" => $name,
        ]));
    }

    private function insertWork(string $id, string $title): void
    {
        self::assertSame(1, $this->database->insert($this->tableNames->works(), [
            "work_id" => $id,
            "work_title" => $title,
            "work_title_status" => "librarian_confirmed",
        ]));
    }

    private function insertContributor(
        string $workId,
        string $authorId,
        string $role,
        int $position
    ): void {
        self::assertSame(1, $this->database->insert($this->tableNames->workContributors(), [
            "work_id" => $workId,
            "author_id" => $authorId,
            "contributor_role" => $role,
            "contributor_position" => $position,
        ]));
    }
}
