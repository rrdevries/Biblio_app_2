<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Metadata\Search\BibliographicEditionProviderPage;
use Biblio\Core\Application\Metadata\Search\BibliographicEditionReference;
use Biblio\Core\Application\Metadata\Search\BibliographicEditionSearchProvider;
use Biblio\Core\Application\Metadata\Search\BibliographicEditionSearchResult;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkReference;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\EditionIsbnMetadata;
use Biblio\Core\Catalog\Isbn10;
use Biblio\Core\Catalog\Isbn13;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Throwable;
use wpdb;

final readonly class WpdbBibliographicEditionSearchProvider implements
    BibliographicEditionSearchProvider
{
    public function __construct(private wpdb $database, private CoreTableNames $tables) {}

    public function key(): string { return "local"; }

    public function searchEditions(
        BibliographicWorkReference $work,
        int $offset,
        int $limit
    ): BibliographicEditionProviderPage {
        $workId = $work->workId()
            ?? throw new ValidationException("Local Edition search requires a canonical Work.");
        if ($offset < 0 || $offset > 1000000 || $limit < 1 || $limit > 100) {
            throw new ValidationException("Local Edition page boundary is invalid.");
        }
        $editions = $this->tables->editions();
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT edition_id,edition_title,isbn_10,isbn_13,explicitly_no_isbn "
                . "FROM `{$editions}` WHERE work_id=%s "
                . "ORDER BY edition_title ASC,edition_id ASC LIMIT %d OFFSET %d",
            $workId->value(),
            $limit + 1,
            $offset
        ));
        $hasMore = count($rows) > $limit;
        if ($hasMore) { $rows = array_slice($rows, 0, $limit); }

        try {
            $contributors = $this->contributors($workId->value());
            $items = array_map(
                function (object $row, int $position) use ($work, $contributors, $offset): BibliographicEditionSearchResult {
                    $isbn = CanonicalIsbnIdentity::fromMetadata($this->isbnMetadata($row));
                    return new BibliographicEditionSearchResult(
                        BibliographicEditionReference::canonical(
                            new EditionId((string) $row->edition_id)
                        ),
                        $work,
                        (string) $row->edition_title,
                        $isbn,
                        null,
                        $contributors,
                        [],
                        [],
                        null,
                        null,
                        null,
                        $offset + $position
                    );
                },
                $rows,
                array_keys($rows)
            );
            return new BibliographicEditionProviderPage(
                $items,
                $hasMore ? $offset + count($rows) : null
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Edition search data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    /** @return list<string> */
    private function contributors(string $workId): array
    {
        $contributors = $this->tables->workContributors();
        $authors = $this->tables->authors();
        $values = $this->database->get_col($this->database->prepare(
            "SELECT a.display_name FROM `{$contributors}` wc "
                . "INNER JOIN `{$authors}` a ON a.author_id=wc.author_id "
                . "WHERE wc.work_id=%s "
                . "ORDER BY wc.contributor_position ASC,a.author_id ASC",
            $workId
        ));
        return array_map(static fn (mixed $value): string => (string) $value, $values);
    }

    private function isbnMetadata(object $row): EditionIsbnMetadata
    {
        $isbn10 = $row->isbn_10 === null ? null : new Isbn10((string) $row->isbn_10);
        $isbn13 = $row->isbn_13 === null ? null : new Isbn13((string) $row->isbn_13);
        if ((int) $row->explicitly_no_isbn === 1) {
            return EditionIsbnMetadata::withoutIsbn();
        }
        return $isbn10 === null && $isbn13 === null
            ? EditionIsbnMetadata::unknown()
            : EditionIsbnMetadata::identified($isbn10, $isbn13);
    }
}
