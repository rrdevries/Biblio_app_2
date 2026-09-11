<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Metadata\Search\BibliographicWorkReference;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicEditionSearchProvider;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicProviderIdentityRepository;

final class BibliographicEditionSearchPersistenceTest extends PersistenceIntegrationTestCase
{
    public function testCanonicalWorkEditionsAreDeterministicallyPageableWithoutItems(): void
    {
        $this->insertWork("edition-search-work", "Abstract Work");
        self::assertSame(1, $this->database->insert($this->tableNames->authors(), [
            "author_id" => "edition-search-author",
            "display_name" => "Example Author",
        ]));
        self::assertSame(1, $this->database->insert($this->tableNames->workContributors(), [
            "work_id" => "edition-search-work",
            "author_id" => "edition-search-author",
            "contributor_role" => "author",
            "contributor_position" => 1,
        ]));
        for ($position = 1; $position <= 12; $position++) {
            self::assertSame(1, $this->database->insert($this->tableNames->editions(), [
                "edition_id" => sprintf("edition-search-%02d", $position),
                "work_id" => "edition-search-work",
                "edition_title" => sprintf("Concrete Edition %02d", $position),
                "isbn_10" => null,
                "isbn_13" => null,
                "explicitly_no_isbn" => 0,
            ]));
        }
        $provider = new WpdbBibliographicEditionSearchProvider(
            $this->database,
            $this->tableNames
        );
        $work = BibliographicWorkReference::canonical(new WorkId("edition-search-work"));

        $first = $provider->searchEditions($work, 0, 10);
        $second = $provider->searchEditions($work, $first->nextOffset() ?? -1, 10);

        self::assertCount(10, $first->items());
        self::assertSame(10, $first->nextOffset());
        self::assertSame("edition-search-01", $first->items()[0]->reference()->editionId()?->value());
        self::assertSame(["Example Author"], $first->items()[0]->contributors());
        self::assertFalse($first->items()[0]->requiresMaterialization());
        self::assertCount(2, $second->items());
        self::assertNull($second->nextOffset());
        self::assertSame("edition-search-12", $second->items()[1]->reference()->editionId()?->value());
        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->items()}`"
        ));
    }

    public function testCanonicalEditionProjectsItsStoredIsbnAndNoOtherMetadata(): void
    {
        $this->insertWork("isbn-work", "ISBN Work");
        self::assertSame(1, $this->database->insert($this->tableNames->editions(), [
            "edition_id" => "isbn-edition",
            "work_id" => "isbn-work",
            "edition_title" => "Concrete title",
            "isbn_10" => "0441172717",
            "isbn_13" => "9780441172719",
            "explicitly_no_isbn" => 0,
        ]));

        $item = (new WpdbBibliographicEditionSearchProvider($this->database, $this->tableNames))
            ->searchEditions(
                BibliographicWorkReference::canonical(new WorkId("isbn-work")),
                0,
                10
            )->items()[0];

        self::assertSame("9780441172719", $item->isbn()?->isbn13()->value());
        self::assertSame("0441172717", $item->isbn()?->isbn10()?->value());
        self::assertSame([], $item->languages());
        self::assertSame([], $item->publishers());
        self::assertNull($item->publicationDate());
        self::assertNull($item->format());
        self::assertNull($item->pageCount());
    }

    public function testProviderWorkReverseLookupReturnsEveryStrongIdentity(): void
    {
        $this->insertWork("mapped-work", "Mapped Work");
        $repository = new WpdbBibliographicProviderIdentityRepository(
            $this->database,
            $this->tableNames
        );
        $work = new WorkId("mapped-work");
        $repository->claimWork("open_library", "work", "/works/OL2W", $work);
        $repository->claimWork("open_library", "work", "/works/OL1W", $work);

        self::assertSame(
            ["/works/OL1W", "/works/OL2W"],
            array_map(
                static fn ($identity): string => $identity->providerRecordId(),
                $repository->providerWorkIdentities($work, "open_library")
            )
        );
    }

    private function insertWork(string $id, string $title): void
    {
        self::assertSame(1, $this->database->insert($this->tableNames->works(), [
            "work_id" => $id,
            "work_title" => $title,
            "work_title_status" => "librarian_confirmed",
        ]));
    }
}
