<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Exception\TransactionException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Reading\ActiveReadingRoundAlreadyExists;
use Biblio\Core\Reading\PersonalWorkReadingStatusSource;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingPeriod;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundIdCollision;
use Biblio\Core\Reading\ReadingRoundLifecycle;
use Biblio\Core\Reading\ReadingRoundOutcome;
use Biblio\Core\Reading\ReadingRoundProvenance;
use Biblio\Core\Reading\ReadingRoundVersion;
use Biblio\Core\Reading\ReadingSource;
use Biblio\Core\Reading\WritableReadingRoundRepository;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use wpdb;

final readonly class WpdbReadingRoundRepository implements
    WritableReadingRoundRepository,
    PersonalWorkReadingStatusSource
{
    private const DATABASE_DATE_FORMAT = "Y-m-d H:i:s.u";
    private WpdbTransactionConnection $transactionConnection;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
        $this->transactionConnection = new WpdbTransactionConnection($database);
    }

    public function addForUser(
        UserId $authenticatedUserId,
        ReadingRound $readingRound
    ): void {
        $this->assertOwner($authenticatedUserId, $readingRound);

        if (!$this->sourceBelongsToWork($readingRound)) {
            throw new PersistenceException(
                "Reading Round source and Work are inconsistent.",
                failureReason: FailureReason::PersistenceWriteFailed
            );
        }

        $period = $readingRound->period();
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $this->database->insert(
                $this->tableNames->readingRounds(),
                [
                    "reading_round_id" => $readingRound->id()->value(),
                    "user_id" => $readingRound->userId()->value(),
                    "work_id" => $readingRound->workId()->value(),
                    "item_id" => $readingRound->source()?->itemId()?->value(),
                    "external_loan_id" => $readingRound->source()
                        ?->externalLoanId()?->value(),
                    "started_at" => $this->formatNullableInstant(
                        $readingRound->legacyStartedAt()
                    ),
                    "round_outcome" => $readingRound->outcome()?->value,
                    "provenance" => $readingRound->provenance()->value,
                    "reading_started_year" => $period->startedOn()?->yearValue(),
                    "reading_started_month" => $period->startedOn()?->monthValue(),
                    "reading_started_day" => $period->startedOn()?->dayValue(),
                    "reading_finished_year" => $period->finishedOn()?->yearValue(),
                    "reading_finished_month" => $period->finishedOn()?->monthValue(),
                    "reading_finished_day" => $period->finishedOn()?->dayValue(),
                    "created_at" => $this->formatNullableInstant(
                        $readingRound->createdAt()
                    ),
                    "updated_at" => $this->formatNullableInstant(
                        $readingRound->updatedAt()
                    ),
                    "ended_at" => $this->formatNullableInstant(
                        $readingRound->endedAt()
                    ),
                    "round_version" => $readingRound->version()->value(),
                ],
                [
                    "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s",
                    "%d", "%d", "%d", "%d", "%d", "%d", "%s", "%s",
                    "%s", "%d",
                ]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result === 1) {
            return;
        }

        $this->throwInsertFailure($this->database->last_error);
    }

    public function replaceIfVersionMatches(
        UserId $authenticatedUserId,
        ReadingRound $replacement,
        ReadingRoundVersion $expectedVersion,
        ReadingRoundLifecycle $expectedLifecycle
    ): bool {
        $this->assertTransactionActive();
        $this->assertOwner($authenticatedUserId, $replacement);

        if ($replacement->version()->value() !== $expectedVersion->value() + 1) {
            throw new PersistenceException(
                "Reading Round replacement must increment version once.",
                failureReason: FailureReason::PersistenceWriteFailed
            );
        }

        if (!$this->sourceBelongsToWork($replacement)) {
            throw new PersistenceException(
                "Reading Round source and Work are inconsistent.",
                failureReason: FailureReason::PersistenceWriteFailed
            );
        }

        $period = $replacement->period();
        $table = $this->tableNames->readingRounds();
        $lifecyclePredicate = $expectedLifecycle === ReadingRoundLifecycle::Active
            ? "round_outcome IS NULL"
            : "round_outcome IS NOT NULL";
        $itemSql = $this->nullableSqlString(
            $replacement->source()?->itemId()?->value()
        );
        $loanSql = $this->nullableSqlString(
            $replacement->source()?->externalLoanId()?->value()
        );
        $outcomeSql = $this->nullableSqlString($replacement->outcome()?->value);
        $startedYearSql = $this->nullableSqlInt(
            $period->startedOn()?->yearValue()
        );
        $startedMonthSql = $this->nullableSqlInt(
            $period->startedOn()?->monthValue()
        );
        $startedDaySql = $this->nullableSqlInt(
            $period->startedOn()?->dayValue()
        );
        $finishedYearSql = $this->nullableSqlInt(
            $period->finishedOn()?->yearValue()
        );
        $finishedMonthSql = $this->nullableSqlInt(
            $period->finishedOn()?->monthValue()
        );
        $finishedDaySql = $this->nullableSqlInt(
            $period->finishedOn()?->dayValue()
        );
        $updatedSql = $this->nullableSqlString(
            $this->formatNullableInstant($replacement->updatedAt())
        );
        $endedSql = $this->nullableSqlString(
            $this->formatNullableInstant($replacement->endedAt())
        );
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $this->database->query($this->database->prepare(
                "UPDATE `{$table}` SET item_id = {$itemSql}, "
                . "external_loan_id = {$loanSql}, round_outcome = {$outcomeSql}, "
                . "reading_started_year = {$startedYearSql}, "
                . "reading_started_month = {$startedMonthSql}, "
                . "reading_started_day = {$startedDaySql}, "
                . "reading_finished_year = {$finishedYearSql}, "
                . "reading_finished_month = {$finishedMonthSql}, "
                . "reading_finished_day = {$finishedDaySql}, "
                . "updated_at = {$updatedSql}, ended_at = {$endedSql}, "
                . "round_version = %d WHERE reading_round_id = %s "
                . "AND user_id = %s AND round_version = %d "
                . "AND {$lifecyclePredicate}",
                $replacement->version()->value(),
                $replacement->id()->value(),
                $authenticatedUserId->value(),
                $expectedVersion->value()
            ));
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result === false) {
            $conflict = WpdbErrorTranslator::conflict($this->database->last_error);

            if ($conflict !== null && in_array($conflict->constraintName(), [
                "one_active_item_round_per_user",
                "one_active_external_round_per_user",
            ], true)) {
                throw new ActiveReadingRoundAlreadyExists(
                    WpdbErrorTranslator::diagnostic(
                        "Reading Round update",
                        $this->database->last_error
                    )
                );
            }

            throw WpdbErrorTranslator::writeFailure(
                "Could not update Reading Round.",
                $this->database->last_error
            );
        }

        return $result === 1;
    }

    public function deleteHistoricalIfVersionMatches(
        UserId $authenticatedUserId,
        ReadingRoundId $readingRoundId,
        ReadingRoundVersion $expectedVersion
    ): bool {
        $this->assertTransactionActive();
        $table = $this->tableNames->readingRounds();
        $result = $this->database->query($this->database->prepare(
            "DELETE FROM `{$table}` WHERE reading_round_id = %s "
            . "AND user_id = %s AND round_version = %d "
            . "AND provenance = 'historical_manual'",
            $readingRoundId->value(),
            $authenticatedUserId->value(),
            $expectedVersion->value()
        ));

        if ($result === false) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not delete historical Reading Round.",
                $this->database->last_error
            );
        }

        return $result === 1;
    }

    public function findForUser(
        ReadingRoundId $readingRoundId,
        UserId $userId
    ): ?ReadingRound {
        return $this->findOne($readingRoundId, $userId, false);
    }

    public function findForUserForUpdate(
        ReadingRoundId $readingRoundId,
        UserId $userId
    ): ?ReadingRound {
        $this->assertTransactionActive();

        return $this->findOne($readingRoundId, $userId, true);
    }

    public function findActiveForUserAndSource(
        UserId $userId,
        ReadingSource $source
    ): ?ReadingRound {
        $table = $this->tableNames->readingRounds();
        $sourceColumn = $source->itemId() !== null
            ? "item_id"
            : "external_loan_id";
        $sourceId = $source->itemId()?->value()
            ?? $source->externalLoanId()?->value();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT {$this->selectColumns()} FROM `{$table}` "
            . "WHERE user_id = %s AND round_outcome IS NULL "
            . "AND `{$sourceColumn}` = %s",
            $userId->value(),
            $sourceId
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    public function findAllForUserAndWork(
        UserId $userId,
        WorkId $workId
    ): array {
        $table = $this->tableNames->readingRounds();
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT {$this->selectColumns()} FROM `{$table}` "
            . "WHERE user_id = %s AND work_id = %s",
            $userId->value(),
            $workId->value()
        ));

        return array_map($this->hydrate(...), $rows);
    }

    public function findAllForUserAndWorks(UserId $userId, array $workIds): array
    {
        $result = [];
        foreach ($workIds as $workId) {
            $result[$workId->value()] = [];
        }
        if ($workIds === []) {
            return $result;
        }

        $table = $this->tableNames->readingRounds();
        $placeholders = implode(',', array_fill(0, count($workIds), '%s'));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT {$this->selectColumns()} FROM `{$table}` "
                . "WHERE user_id=%s AND work_id IN ({$placeholders}) "
                . "ORDER BY work_id,reading_round_id",
            $userId->value(),
            ...array_map(static fn (WorkId $id): string => $id->value(), $workIds)
        ));
        foreach ($rows as $row) {
            $result[(string) $row->work_id][] = $this->hydrate($row);
        }
        return $result;
    }

    private function findOne(
        ReadingRoundId $readingRoundId,
        UserId $userId,
        bool $forUpdate
    ): ?ReadingRound {
        $table = $this->tableNames->readingRounds();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT {$this->selectColumns()} FROM `{$table}` "
            . "WHERE reading_round_id = %s AND user_id = %s"
            . ($forUpdate ? " FOR UPDATE" : ""),
            $readingRoundId->value(),
            $userId->value()
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    private function hydrate(object $row): ReadingRound
    {
        try {
            $source = null;

            if ($row->item_id !== null) {
                $source = ReadingSource::libraryItem(
                    new ItemId((string) $row->item_id)
                );
            } elseif ($row->external_loan_id !== null) {
                $source = ReadingSource::externalLoan(
                    new ExternalLoanId((string) $row->external_loan_id)
                );
            }

            return new ReadingRound(
                new ReadingRoundId((string) $row->reading_round_id),
                new UserId((string) $row->user_id),
                new WorkId((string) $row->work_id),
                $source,
                $row->round_outcome === null
                    ? null
                    : ReadingRoundOutcome::from((string) $row->round_outcome),
                ReadingRoundProvenance::from((string) $row->provenance),
                new ReadingPeriod(
                    $this->hydrateReadingDate(
                        $row->reading_started_year,
                        $row->reading_started_month,
                        $row->reading_started_day
                    ),
                    $this->hydrateReadingDate(
                        $row->reading_finished_year,
                        $row->reading_finished_month,
                        $row->reading_finished_day
                    )
                ),
                $this->hydrateNullableInstant($row->started_at),
                $this->hydrateNullableInstant($row->created_at),
                $this->hydrateNullableInstant($row->updated_at),
                $this->hydrateNullableInstant($row->ended_at),
                new ReadingRoundVersion((int) $row->round_version)
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Reading Round data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    private function hydrateReadingDate(mixed $year, mixed $month, mixed $day): ?ReadingDate
    {
        if ($year === null) {
            return null;
        }

        return new ReadingDate(
            (int) $year,
            $month === null ? null : (int) $month,
            $day === null ? null : (int) $day
        );
    }

    private function selectColumns(): string
    {
        return "reading_round_id, user_id, work_id, item_id, external_loan_id, "
            . "started_at, round_outcome, provenance, reading_started_year, "
            . "reading_started_month, reading_started_day, reading_finished_year, "
            . "reading_finished_month, reading_finished_day, created_at, "
            . "updated_at, ended_at, round_version";
    }

    private function throwInsertFailure(string $error): never
    {
        $conflict = WpdbErrorTranslator::conflict($error);

        if ($conflict?->constraintName() === "PRIMARY") {
            throw new ReadingRoundIdCollision(
                WpdbErrorTranslator::diagnostic("Reading Round insert", $error)
            );
        }

        if ($conflict !== null && in_array($conflict->constraintName(), [
            "one_active_item_round_per_user",
            "one_active_external_round_per_user",
        ], true)) {
            throw new ActiveReadingRoundAlreadyExists(
                WpdbErrorTranslator::diagnostic("Reading Round insert", $error)
            );
        }

        throw WpdbErrorTranslator::writeFailure(
            "Could not persist Reading Round.",
            $error
        );
    }

    private function assertOwner(UserId $actorId, ReadingRound $round): void
    {
        if (!$actorId->equals($round->userId())) {
            throw new AuthorizationException(
                "Cannot persist a Reading Round for another user."
            );
        }
    }

    private function assertTransactionActive(): void
    {
        if ($this->transactionConnection->isTransactionActive() !== true) {
            throw new TransactionException(
                "Reading Round mutation requires an active transaction.",
                FailureReason::TransactionBeginFailed
            );
        }
    }

    private function formatNullableInstant(?DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new DateTimeZone("UTC"))
            ->format(self::DATABASE_DATE_FORMAT);
    }

    private function hydrateNullableInstant(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            "!" . self::DATABASE_DATE_FORMAT,
            (string) $value,
            new DateTimeZone("UTC")
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && ($errors["warning_count"] > 0 || $errors["error_count"] > 0))
        ) {
            throw new PersistenceException(
                "Stored Reading Round instant is invalid.",
                failureReason: FailureReason::PersistenceReadFailed
            );
        }

        return $date;
    }

    private function nullableSqlString(?string $value): string
    {
        return $value === null
            ? "NULL"
            : $this->database->prepare("%s", $value);
    }

    private function nullableSqlInt(?int $value): string
    {
        return $value === null ? "NULL" : (string) $value;
    }

    private function sourceBelongsToWork(ReadingRound $readingRound): bool
    {
        $source = $readingRound->source();

        if ($source === null) {
            return true;
        }

        if ($source->itemId() !== null) {
            $items = $this->tableNames->items();
            $editions = $this->tableNames->editions();
            $sourceWorkId = $this->database->get_var($this->database->prepare(
                "SELECT e.work_id FROM `{$items}` i INNER JOIN `{$editions}` e "
                . "ON e.edition_id = i.edition_id WHERE i.item_id = %s",
                $source->itemId()->value()
            ));
        } else {
            $externalLoans = $this->tableNames->externalLoans();
            $sourceWorkId = $this->database->get_var($this->database->prepare(
                "SELECT work_id FROM `{$externalLoans}` WHERE external_loan_id = %s",
                $source->externalLoanId()?->value()
            ));
        }

        return is_string($sourceWorkId)
            && $sourceWorkId === $readingRound->workId()->value();
    }
}
