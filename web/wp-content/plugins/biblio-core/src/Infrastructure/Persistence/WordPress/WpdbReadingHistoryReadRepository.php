<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Reading\History\ReadingHistoryCursor;
use Biblio\Core\Application\Reading\History\ReadingHistoryEntry;
use Biblio\Core\Application\Reading\History\ReadingHistoryPage;
use Biblio\Core\Application\Reading\History\ReadingHistoryPageSize;
use Biblio\Core\Application\Reading\History\ReadingHistoryReadRepository;
use Biblio\Core\Application\Reading\History\ReadingHistorySourceType;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundOutcome;
use Biblio\Core\Reading\ReadingRoundProvenance;
use Throwable;
use wpdb;

final readonly class WpdbReadingHistoryReadRepository implements
    ReadingHistoryReadRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
    }

    public function forUserAndWork(
        UserId $userId,
        WorkId $workId,
        ReadingHistoryPageSize $pageSize,
        ?ReadingHistoryCursor $cursor
    ): ReadingHistoryPage {
        $table = $this->tableNames->readingRounds();
        $earliest = $this->finishedEarliestExpression();
        $latest = $this->finishedLatestExpression();
        $where = "user_id = %s AND work_id = %s "
            . "AND round_outcome IN ('completed', 'stopped')";
        $parameters = [$userId->value(), $workId->value()];

        if ($cursor !== null) {
            $where .= " AND (({$earliest}) < %d "
                . "OR (({$earliest}) = %d AND ({$latest}) < %d) "
                . "OR (({$earliest}) = %d AND ({$latest}) = %d "
                . "AND reading_round_id < %s))";
            $parameters[] = $cursor->finishedEarliest();
            $parameters[] = $cursor->finishedEarliest();
            $parameters[] = $cursor->finishedLatest();
            $parameters[] = $cursor->finishedEarliest();
            $parameters[] = $cursor->finishedLatest();
            $parameters[] = $cursor->tieBreaker()->value();
        }

        $parameters[] = $pageSize->value() + 1;
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT round_outcome, provenance, item_id, external_loan_id, "
            . "reading_started_year, reading_started_month, "
            . "reading_started_day, reading_finished_year, "
            . "reading_finished_month, reading_finished_day, "
            . "reading_round_id, {$earliest} AS finished_earliest, "
            . "{$latest} AS finished_latest FROM `{$table}` "
            . "WHERE {$where} ORDER BY finished_earliest DESC, "
            . "finished_latest DESC, reading_round_id DESC LIMIT %d",
            ...$parameters
        ));

        if (!is_array($rows) || $this->database->last_error !== "") {
            throw new PersistenceException(
                "Could not read Reading history.",
                0,
                WpdbErrorTranslator::diagnostic(
                    "Reading history projection",
                    $this->database->last_error
                ),
                FailureReason::PersistenceReadFailed
            );
        }

        $hasMore = count($rows) > $pageSize->value();

        if ($hasMore) {
            $rows = array_slice($rows, 0, $pageSize->value());
        }

        try {
            $entries = array_map($this->hydrate(...), $rows);
            $last = $rows === [] ? null : $rows[array_key_last($rows)];

            return new ReadingHistoryPage(
                $entries,
                $hasMore && $last !== null
                    ? new ReadingHistoryCursor(
                        (int) $last->finished_earliest,
                        (int) $last->finished_latest,
                        new ReadingRoundId((string) $last->reading_round_id)
                    )
                    : null
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Reading history projection data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    private function hydrate(object $row): ReadingHistoryEntry
    {
        $provenance = ReadingRoundProvenance::from((string) $row->provenance);
        $startedOn = $this->hydrateReadingDate(
            $row->reading_started_year,
            $row->reading_started_month,
            $row->reading_started_day
        );
        $finishedOn = $this->hydrateReadingDate(
            $row->reading_finished_year,
            $row->reading_finished_month,
            $row->reading_finished_day
        );

        if (
            $finishedOn === null
            || ($row->item_id !== null && $row->external_loan_id !== null)
            || (
                $provenance === ReadingRoundProvenance::LegacySourceStarted
                && $startedOn !== null
            )
        ) {
            throw new PersistenceException(
                "Stored Reading history row violates its projection contract.",
                failureReason: FailureReason::PersistenceReadFailed
            );
        }

        return new ReadingHistoryEntry(
            ReadingRoundOutcome::from((string) $row->round_outcome),
            $startedOn,
            $finishedOn,
            $this->sourceType($row),
            $provenance === ReadingRoundProvenance::HistoricalManual
        );
    }

    private function sourceType(object $row): ReadingHistorySourceType
    {
        if ($row->item_id !== null) {
            return ReadingHistorySourceType::LibraryItem;
        }

        if ($row->external_loan_id !== null) {
            return ReadingHistorySourceType::ExternalLoan;
        }

        return ReadingHistorySourceType::Unknown;
    }

    private function hydrateReadingDate(
        mixed $year,
        mixed $month,
        mixed $day
    ): ?ReadingDate {
        if ($year === null) {
            if ($month !== null || $day !== null) {
                throw new PersistenceException(
                    "Stored Reading date has components without a year.",
                    failureReason: FailureReason::PersistenceReadFailed
                );
            }

            return null;
        }

        return new ReadingDate(
            (int) $year,
            $month === null ? null : (int) $month,
            $day === null ? null : (int) $day
        );
    }

    private function finishedEarliestExpression(): string
    {
        return "reading_finished_year * 10000 "
            . "+ COALESCE(reading_finished_month, 1) * 100 "
            . "+ COALESCE(reading_finished_day, 1)";
    }

    private function finishedLatestExpression(): string
    {
        return "reading_finished_year * 10000 "
            . "+ COALESCE(reading_finished_month, 12) * 100 "
            . "+ COALESCE(reading_finished_day, DAYOFMONTH(LAST_DAY(CONCAT("
            . "reading_finished_year, '-', "
            . "COALESCE(reading_finished_month, 12), '-01'))))";
    }
}
