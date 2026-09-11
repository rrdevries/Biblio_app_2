<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Metadata\Search\BibliographicAuthorReference;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorWorkProviderPage;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorWorkSearchProvider;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkAuthor;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkReference;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSearchResult;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSeriesContext;
use Biblio\Core\Catalog\AuthorId;
use Biblio\Core\Catalog\SeriesId;
use Biblio\Core\Catalog\SeriesPosition;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Throwable;
use wpdb;

final readonly class WpdbBibliographicAuthorWorkSearchProvider implements
    BibliographicAuthorWorkSearchProvider
{
    public function __construct(private wpdb $database, private CoreTableNames $tables) {}

    public function key(): string { return "local"; }

    public function searchWorksForAuthor(
        BibliographicAuthorReference $author,
        int $offset,
        int $limit
    ): BibliographicAuthorWorkProviderPage {
        $authorId = $author->authorId()
            ?? throw new ValidationException("Local Works-by-Author requires a canonical Author.");
        if ($offset < 0 || $offset > 1000000 || $limit < 1 || $limit > 100) {
            throw new ValidationException("Local Works-by-Author page boundary is invalid.");
        }
        $authorExists = $this->database->get_var($this->database->prepare(
            "SELECT 1 FROM `{$this->tables->authors()}` WHERE author_id=%s",
            $authorId->value()
        ));
        if ($authorExists === null) {
            throw new ValidationException("Selected canonical Author is not available.");
        }
        $works = $this->tables->works();
        $contributors = $this->tables->workContributors();
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT w.work_id,w.work_title FROM `{$works}` w "
                . "INNER JOIN `{$contributors}` selected_author "
                . "ON selected_author.work_id=w.work_id "
                . "WHERE selected_author.author_id=%s "
                . "AND selected_author.contributor_role IN ('author','co_author') "
                . "ORDER BY w.work_title ASC,w.work_id ASC LIMIT %d OFFSET %d",
            $authorId->value(),
            $limit + 1,
            $offset
        ));
        $hasMore = count($rows) > $limit;
        if ($hasMore) { $rows = array_slice($rows, 0, $limit); }

        try {
            $workIds = array_map(static fn (object $row): string => (string) $row->work_id, $rows);
            $authorsByWork = $this->authorsByWork($workIds);
            $seriesByWork = $this->seriesByWork($workIds);
            $items = array_map(
                static fn (object $row, int $position): BibliographicWorkSearchResult =>
                    new BibliographicWorkSearchResult(
                        BibliographicWorkReference::canonical(new WorkId((string) $row->work_id)),
                        (string) $row->work_title,
                        $authorsByWork[(string) $row->work_id] ?? [],
                        $seriesByWork[(string) $row->work_id] ?? [],
                        $offset + $position
                    ),
                $rows,
                array_keys($rows)
            );
            return new BibliographicAuthorWorkProviderPage(
                $items,
                $hasMore ? $offset + count($rows) : null
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Works-by-Author data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    /**
     * @param list<string> $workIds
     * @return array<string,list<BibliographicWorkAuthor>>
     */
    private function authorsByWork(array $workIds): array
    {
        if ($workIds === []) { return []; }
        $contributors = $this->tables->workContributors();
        $authors = $this->tables->authors();
        $placeholders = implode(",", array_fill(0, count($workIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT wc.work_id,a.author_id,a.display_name "
                . "FROM `{$contributors}` wc INNER JOIN `{$authors}` a "
                . "ON a.author_id=wc.author_id WHERE wc.work_id IN ({$placeholders}) "
                . "AND wc.contributor_role IN ('author','co_author') "
                . "ORDER BY wc.work_id,wc.contributor_position,a.author_id",
            ...$workIds
        ));
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->work_id][] = new BibliographicWorkAuthor(
                (string) $row->display_name,
                new AuthorId((string) $row->author_id)
            );
        }
        return $result;
    }

    /**
     * @param list<string> $workIds
     * @return array<string,list<BibliographicWorkSeriesContext>>
     */
    private function seriesByWork(array $workIds): array
    {
        if ($workIds === []) { return []; }
        $memberships = $this->tables->workSeries();
        $series = $this->tables->series();
        $placeholders = implode(",", array_fill(0, count($workIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT ws.work_id,s.series_id,s.display_name,ws.series_position "
                . "FROM `{$memberships}` ws INNER JOIN `{$series}` s "
                . "ON s.series_id=ws.series_id WHERE ws.work_id IN ({$placeholders}) "
                . "ORDER BY ws.work_id,s.display_name,s.series_id",
            ...$workIds
        ));
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->work_id][] = new BibliographicWorkSeriesContext(
                (string) $row->display_name,
                new SeriesId((string) $row->series_id),
                $row->series_position === null
                    ? SeriesPosition::unknown()
                    : SeriesPosition::known((string) $row->series_position)
            );
        }
        return $result;
    }
}
