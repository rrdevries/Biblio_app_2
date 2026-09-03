<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\{CatalogRecordAlreadyExists,Series,SeriesId,SeriesPosition,WorkId,WorkSeriesMembership,WritableSeriesRepository};
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Throwable;
use wpdb;

final readonly class WpdbSeriesRepository implements WritableSeriesRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
    }

    public function save(Series $series): void
    {
        $table = $this->tables->series();
        $result = $this->database->query($this->database->prepare(
            "INSERT INTO `{$table}` (series_id,display_name) VALUES (%s,%s) "
                . "ON DUPLICATE KEY UPDATE display_name=VALUES(display_name)",
            $series->id()->value(),
            $series->displayName()
        ));
        if ($result === false) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist Series.",
                $this->database->last_error
            );
        }
    }

    public function addMembership(WorkSeriesMembership $membership): void
    {
        $previous = $this->database->suppress_errors(true);
        try {
            $result = $this->database->insert($this->tables->workSeries(), [
                "work_id" => $membership->workId()->value(),
                "series_id" => $membership->seriesId()->value(),
                "series_position" => $membership->position()->value(),
            ], ["%s", "%s", "%s"]);
        } finally {
            $this->database->suppress_errors($previous);
        }
        if ($result === 1) {
            return;
        }
        if (WpdbErrorTranslator::conflict($this->database->last_error) !== null) {
            throw new CatalogRecordAlreadyExists(WpdbErrorTranslator::diagnostic(
                "Work-Series insert",
                $this->database->last_error
            ));
        }
        throw WpdbErrorTranslator::writeFailure(
            "Could not persist Work-Series membership.",
            $this->database->last_error
        );
    }

    public function find(SeriesId $seriesId): ?Series
    {
        $table = $this->tables->series();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT series_id,display_name FROM `{$table}` WHERE series_id=%s",
            $seriesId->value()
        ));
        if ($row === null) {
            return null;
        }
        try {
            return new Series(new SeriesId((string) $row->series_id), (string) $row->display_name);
        } catch (Throwable $exception) {
            throw new PersistenceException("Stored Series data is invalid.", 0, $exception, FailureReason::PersistenceReadFailed);
        }
    }

    /**
     * @param list<SeriesId> $seriesIds
     * @return array<string, Series>
     */
    public function findMany(array $seriesIds): array
    {
        if ($seriesIds === []) {
            return [];
        }

        $table = $this->tables->series();
        $placeholders = implode(",", array_fill(0, count($seriesIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT series_id,display_name FROM `{$table}` "
                . "WHERE series_id IN ({$placeholders}) ORDER BY series_id",
            ...array_map(
                static fn (SeriesId $id): string => $id->value(),
                $seriesIds
            )
        ));

        try {
            $result = [];
            foreach ($rows as $row) {
                $series = new Series(
                    new SeriesId((string) $row->series_id),
                    (string) $row->display_name
                );
                $result[$series->id()->value()] = $series;
            }

            return $result;
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Series data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    /**
     * @param list<WorkId> $workIds
     * @return array<string, list<WorkSeriesMembership>>
     */
    public function membershipsForWorks(array $workIds): array
    {
        $result = [];
        foreach ($workIds as $workId) {
            $result[$workId->value()] = [];
        }
        if ($workIds === []) {
            return $result;
        }
        return $this->memberships(
            "work_id",
            array_map(static fn (WorkId $id): string => $id->value(), $workIds),
            $result,
            "work_id,series_id"
        );
    }

    /**
     * @param list<SeriesId> $seriesIds
     * @return array<string, list<WorkSeriesMembership>>
     */
    public function membershipsForSeries(array $seriesIds): array
    {
        $result = [];
        foreach ($seriesIds as $seriesId) {
            $result[$seriesId->value()] = [];
        }
        if ($seriesIds === []) {
            return $result;
        }
        return $this->memberships(
            "series_id",
            array_map(static fn (SeriesId $id): string => $id->value(), $seriesIds),
            $result,
            "series_id,series_position IS NULL,series_position,work_id"
        );
    }

    /**
     * @param list<string> $ids
     * @param array<string, list<WorkSeriesMembership>> $result
     * @return array<string, list<WorkSeriesMembership>>
     */
    private function memberships(string $column, array $ids, array $result, string $order): array
    {
        $table = $this->tables->workSeries();
        $placeholders = implode(",", array_fill(0, count($ids), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT work_id,series_id,series_position FROM `{$table}` "
                . "WHERE {$column} IN ({$placeholders}) ORDER BY {$order}",
            ...$ids
        ));
        try {
            foreach ($rows as $row) {
                $key = (string) $row->{$column};
                $result[$key][] = new WorkSeriesMembership(
                    new WorkId((string) $row->work_id),
                    new SeriesId((string) $row->series_id),
                    $row->series_position === null
                        ? SeriesPosition::unknown()
                        : SeriesPosition::known((string) $row->series_position)
                );
            }
            return $result;
        } catch (Throwable $exception) {
            throw new PersistenceException("Stored Work-Series data is invalid.", 0, $exception, FailureReason::PersistenceReadFailed);
        }
    }
}
