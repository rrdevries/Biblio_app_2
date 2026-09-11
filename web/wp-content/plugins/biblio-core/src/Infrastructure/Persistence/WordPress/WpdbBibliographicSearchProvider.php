<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Metadata\Search\BibliographicAuthorReference;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchPage;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchProvider;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchResult;
use Biblio\Core\Application\Metadata\Search\BibliographicSearchCursor;
use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchQuery;
use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchService;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkAuthor;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkReference;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSearchPage;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSearchProvider;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSearchResult;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSeriesContext;
use Biblio\Core\Catalog\AuthorId;
use Biblio\Core\Catalog\SeriesId;
use Biblio\Core\Catalog\SeriesPosition;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Throwable;
use wpdb;

final readonly class WpdbBibliographicSearchProvider implements
    BibliographicAuthorSearchProvider,
    BibliographicWorkSearchProvider
{
    private const string PROVIDER_KEY = "local";

    public function __construct(private wpdb $database, private CoreTableNames $tables) {}

    public function key(): string { return self::PROVIDER_KEY; }

    public function searchAuthors(
        BibliographicTextSearchQuery $query,
        ?BibliographicSearchCursor $cursor = null
    ): BibliographicAuthorSearchPage {
        $authors = $this->tables->authors();
        [$predicates, $parameters] = $this->authorPredicates($query, "a.display_name");
        $offset = $cursor === null ? 0 : $cursor->presentationOrder() + 1;
        array_push($parameters, BibliographicTextSearchService::PAGE_SIZE + 1, $offset);
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT a.author_id,a.display_name FROM `{$authors}` a WHERE "
                . implode(" AND ", $predicates)
                . " ORDER BY a.display_name ASC,a.author_id ASC LIMIT %d OFFSET %d",
            ...$parameters
        ));
        $hasMore = count($rows) > BibliographicTextSearchService::PAGE_SIZE;
        if ($hasMore) { $rows = array_slice($rows, 0, BibliographicTextSearchService::PAGE_SIZE); }

        try {
            $items = array_map(
                static fn (object $row, int $position): BibliographicAuthorSearchResult =>
                    new BibliographicAuthorSearchResult(
                        BibliographicAuthorReference::canonical(
                            new AuthorId((string) $row->author_id)
                        ),
                        (string) $row->display_name,
                        $offset + $position
                    ),
                $rows,
                array_keys($rows)
            );
            $last = $items === [] ? null : $items[array_key_last($items)];
            return new BibliographicAuthorSearchPage(
                $query,
                $items,
                $hasMore && $last !== null ? $last->cursor($query) : null
            );
        } catch (Throwable $exception) {
            throw $this->invalid("Stored bibliographic Author search data is invalid.", $exception);
        }
    }

    public function searchWorks(
        BibliographicTextSearchQuery $query,
        ?BibliographicSearchCursor $cursor = null
    ): BibliographicWorkSearchPage {
        $works = $this->tables->works();
        $contributors = $this->tables->workContributors();
        $authors = $this->tables->authors();
        $predicates = [];
        $parameters = [];
        foreach ($this->tokens($query) as $token) {
            $pattern = "%" . $this->database->esc_like($token) . "%";
            $predicates[] = "(w.work_title LIKE %s OR EXISTS ("
                . "SELECT 1 FROM `{$contributors}` wc_search "
                . "INNER JOIN `{$authors}` a_search "
                . "ON a_search.author_id=wc_search.author_id "
                . "WHERE wc_search.work_id=w.work_id AND a_search.display_name LIKE %s))";
            array_push($parameters, $pattern, $pattern);
        }
        $offset = $cursor === null ? 0 : $cursor->presentationOrder() + 1;
        array_push($parameters, BibliographicTextSearchService::PAGE_SIZE + 1, $offset);
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT w.work_id,w.work_title FROM `{$works}` w WHERE "
                . implode(" AND ", $predicates)
                . " ORDER BY w.work_title ASC,w.work_id ASC LIMIT %d OFFSET %d",
            ...$parameters
        ));
        $hasMore = count($rows) > BibliographicTextSearchService::PAGE_SIZE;
        if ($hasMore) { $rows = array_slice($rows, 0, BibliographicTextSearchService::PAGE_SIZE); }

        try {
            $workIds = array_map(static fn (object $row): string => (string) $row->work_id, $rows);
            $authorsByWork = $this->authorsByWork($workIds);
            $seriesByWork = $this->seriesByWork($workIds);
            $items = array_map(
                static fn (object $row, int $position): BibliographicWorkSearchResult =>
                    new BibliographicWorkSearchResult(
                        BibliographicWorkReference::canonical(
                            new WorkId((string) $row->work_id)
                        ),
                        (string) $row->work_title,
                        $authorsByWork[(string) $row->work_id] ?? [],
                        $seriesByWork[(string) $row->work_id] ?? [],
                        $offset + $position
                    ),
                $rows,
                array_keys($rows)
            );
            $last = $items === [] ? null : $items[array_key_last($items)];
            return new BibliographicWorkSearchPage(
                $query,
                $items,
                $hasMore && $last !== null ? $last->cursor($query) : null
            );
        } catch (Throwable $exception) {
            throw $this->invalid("Stored bibliographic Work search data is invalid.", $exception);
        }
    }

    /** @return list<string> */
    private function tokens(BibliographicTextSearchQuery $query): array
    {
        $tokens = preg_split('/\s+/u', $query->value());
        if ($tokens === false || $tokens === []) {
            throw new \InvalidArgumentException("Invalid local bibliographic search query.");
        }
        return $tokens;
    }

    /** @return array{list<string>,list<string>} */
    private function authorPredicates(
        BibliographicTextSearchQuery $query,
        string $field
    ): array {
        $predicates = [];
        $parameters = [];
        foreach ($this->tokens($query) as $token) {
            $predicates[] = "{$field} LIKE %s";
            $parameters[] = "%" . $this->database->esc_like($token) . "%";
        }
        return [$predicates, $parameters];
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

    private function invalid(string $message, Throwable $exception): PersistenceException
    {
        return new PersistenceException(
            $message,
            0,
            $exception,
            FailureReason::PersistenceReadFailed
        );
    }
}
