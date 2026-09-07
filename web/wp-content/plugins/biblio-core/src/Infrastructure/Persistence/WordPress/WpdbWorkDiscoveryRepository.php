<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Catalog\Discovery\{WorkDiscoveryCursor,WorkDiscoveryLimit,WorkDiscoveryPage,WorkDiscoveryRepository,WorkDiscoverySearchTerm,WorkDiscoverySeriesView,WorkDiscoveryView};
use Biblio\Core\Catalog\{Author,AuthorId,Series,SeriesId,SeriesPosition,WorkId,WorkTitleStatus};
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Throwable;
use wpdb;

final readonly class WpdbWorkDiscoveryRepository implements WorkDiscoveryRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
    }

    public function search(
        WorkDiscoverySearchTerm $search,
        WorkDiscoveryLimit $limit,
        ?WorkDiscoveryCursor $cursor
    ): WorkDiscoveryPage {
        $works = $this->tableNames->works();
        $contributors = $this->tableNames->workContributors();
        $authors = $this->tableNames->authors();
        $pattern = "%" . $this->database->esc_like($search->value()) . "%";
        $where = "(w.work_title LIKE %s OR EXISTS ("
            . "SELECT 1 FROM `{$contributors}` wc_search "
            . "INNER JOIN `{$authors}` a_search "
            . "ON a_search.author_id=wc_search.author_id "
            . "WHERE wc_search.work_id=w.work_id AND a_search.display_name LIKE %s))";
        $parameters = [$pattern, $pattern];

        if ($cursor !== null) {
            $where .= " AND (w.work_title > %s OR "
                . "(w.work_title = %s AND w.work_id > %s))";
            $parameters[] = $cursor->title();
            $parameters[] = $cursor->title();
            $parameters[] = $cursor->workId()->value();
        }

        $parameters[] = $limit->value() + 1;
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT w.work_id,w.work_title,w.work_title_status "
                . "FROM `{$works}` w WHERE {$where} "
                . "ORDER BY w.work_title ASC,w.work_id ASC LIMIT %d",
            ...$parameters
        ));
        $hasMore = count($rows) > $limit->value();
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit->value());
        }

        $workIds = array_map(
            static fn (object $row): string => (string) $row->work_id,
            $rows
        );
        $authorsByWork = $this->authorsByWork($workIds);
        $seriesByWork = $this->seriesByWork($workIds);
        $views = array_map(
            fn (object $row): WorkDiscoveryView => $this->work(
                $row,
                $authorsByWork[(string) $row->work_id] ?? [],
                $seriesByWork[(string) $row->work_id] ?? []
            ),
            $rows
        );
        $last = $views === [] ? null : $views[array_key_last($views)];

        return new WorkDiscoveryPage(
            $views,
            $hasMore && $last !== null
                ? new WorkDiscoveryCursor($search, $last->title(), $last->workId())
                : null
        );
    }

    /**
     * @param list<string> $workIds
     * @return array<string, list<Author>>
     */
    private function authorsByWork(array $workIds): array
    {
        if ($workIds === []) {
            return [];
        }

        $contributors = $this->tableNames->workContributors();
        $authors = $this->tableNames->authors();
        $placeholders = implode(",", array_fill(0, count($workIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT wc.work_id,a.author_id,a.display_name "
                . "FROM `{$contributors}` wc "
                . "INNER JOIN `{$authors}` a ON a.author_id=wc.author_id "
                . "WHERE wc.work_id IN ({$placeholders}) "
                . "ORDER BY wc.work_id,wc.contributor_position,a.author_id",
            ...$workIds
        ));

        try {
            $result = [];
            foreach ($rows as $row) {
                $result[(string) $row->work_id][] = new Author(
                    new AuthorId((string) $row->author_id),
                    (string) $row->display_name
                );
            }

            return $result;
        } catch (Throwable $exception) {
            throw $this->invalid("Stored Work discovery author data is invalid.", $exception);
        }
    }

    /**
     * @param list<string> $workIds
     * @return array<string, list<WorkDiscoverySeriesView>>
     */
    private function seriesByWork(array $workIds): array
    {
        if ($workIds === []) {
            return [];
        }

        $memberships = $this->tableNames->workSeries();
        $series = $this->tableNames->series();
        $placeholders = implode(",", array_fill(0, count($workIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT ws.work_id,s.series_id,s.display_name,ws.series_position "
                . "FROM `{$memberships}` ws "
                . "INNER JOIN `{$series}` s ON s.series_id=ws.series_id "
                . "WHERE ws.work_id IN ({$placeholders}) "
                . "ORDER BY ws.work_id,s.display_name,s.series_id",
            ...$workIds
        ));

        try {
            $result = [];
            foreach ($rows as $row) {
                $result[(string) $row->work_id][] = new WorkDiscoverySeriesView(
                    new Series(
                        new SeriesId((string) $row->series_id),
                        (string) $row->display_name
                    ),
                    $row->series_position === null
                        ? SeriesPosition::unknown()
                        : SeriesPosition::known((string) $row->series_position)
                );
            }

            return $result;
        } catch (Throwable $exception) {
            throw $this->invalid("Stored Work discovery series data is invalid.", $exception);
        }
    }

    /**
     * @param list<Author> $authors
     * @param list<WorkDiscoverySeriesView> $series
     */
    private function work(object $row, array $authors, array $series): WorkDiscoveryView
    {
        try {
            return new WorkDiscoveryView(
                new WorkId((string) $row->work_id),
                (string) $row->work_title,
                WorkTitleStatus::from((string) $row->work_title_status),
                $authors,
                $series
            );
        } catch (Throwable $exception) {
            throw $this->invalid("Stored Work discovery data is invalid.", $exception);
        }
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
