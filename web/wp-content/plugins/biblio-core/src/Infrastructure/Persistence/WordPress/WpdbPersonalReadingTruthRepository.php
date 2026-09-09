<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Exception\TransactionException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Reading\PersonalReadingTruth;
use Biblio\Core\Reading\PersonalReadingTruthState;
use Biblio\Core\Reading\PersonalReadingTruthStale;
use Biblio\Core\Reading\PersonalReadingTruthVersion;
use Biblio\Core\Reading\WritablePersonalReadingTruthRepository;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use wpdb;

final readonly class WpdbPersonalReadingTruthRepository implements
    WritablePersonalReadingTruthRepository
{
    private const DATE_FORMAT = "Y-m-d H:i:s.u";
    private WpdbTransactionConnection $connection;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
        $this->connection = new WpdbTransactionConnection($database);
    }

    public function findForUserAndWork(
        UserId $userId,
        WorkId $workId
    ): ?PersonalReadingTruth {
        return $this->findOne($userId, $workId, false);
    }

    public function findForUserAndWorkForUpdate(
        UserId $userId,
        WorkId $workId
    ): ?PersonalReadingTruth {
        $this->assertTransactionActive();

        return $this->findOne($userId, $workId, true);
    }

    public function findAllForUserAndWorks(
        UserId $userId,
        array $workIds
    ): array {
        if ($workIds === []) {
            return [];
        }

        $table = $this->tables->personalReadingTruths();
        $placeholders = implode(",", array_fill(0, count($workIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT user_id,work_id,truth_state,truth_version,created_at,updated_at "
                . "FROM `{$table}` WHERE user_id=%s AND work_id IN ({$placeholders})",
            $userId->value(),
            ...array_map(static fn (WorkId $id): string => $id->value(), $workIds)
        ));
        $result = [];
        foreach ($rows as $row) {
            $truth = $this->hydrate($row);
            $result[$truth->workId()->value()] = $truth;
        }

        return $result;
    }

    public function add(PersonalReadingTruth $truth): void
    {
        $this->assertTransactionActive();
        $previous = $this->database->suppress_errors(true);
        try {
            $result = $this->database->insert(
                $this->tables->personalReadingTruths(),
                [
                    "user_id" => $truth->userId()->value(),
                    "work_id" => $truth->workId()->value(),
                    "truth_state" => $truth->state()->value,
                    "truth_version" => $truth->version()->value(),
                    "created_at" => $this->date($truth->createdAt()),
                    "updated_at" => $this->date($truth->updatedAt()),
                ],
                ["%s", "%s", "%s", "%d", "%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previous);
        }

        if ($result !== 1) {
            if (WpdbErrorTranslator::conflict($this->database->last_error) !== null) {
                throw new PersonalReadingTruthStale();
            }
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist Personal Reading Truth.",
                $this->database->last_error
            );
        }
    }

    public function replaceIfVersionMatches(
        PersonalReadingTruth $replacement,
        PersonalReadingTruthVersion $expectedVersion
    ): bool {
        $this->assertTransactionActive();
        if ($replacement->version()->value() !== $expectedVersion->value() + 1) {
            throw new PersistenceException(
                "Personal Reading Truth replacement must increment version once.",
                failureReason: FailureReason::PersistenceWriteFailed
            );
        }

        $result = $this->database->update(
            $this->tables->personalReadingTruths(),
            [
                "truth_state" => $replacement->state()->value,
                "truth_version" => $replacement->version()->value(),
                "updated_at" => $this->date($replacement->updatedAt()),
            ],
            [
                "user_id" => $replacement->userId()->value(),
                "work_id" => $replacement->workId()->value(),
                "truth_version" => $expectedVersion->value(),
            ],
            ["%s", "%d", "%s"],
            ["%s", "%s", "%d"]
        );

        if ($result === false) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not update Personal Reading Truth.",
                $this->database->last_error
            );
        }

        return $result === 1;
    }

    private function findOne(
        UserId $userId,
        WorkId $workId,
        bool $forUpdate
    ): ?PersonalReadingTruth {
        $table = $this->tables->personalReadingTruths();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT user_id,work_id,truth_state,truth_version,created_at,updated_at "
                . "FROM `{$table}` WHERE user_id=%s AND work_id=%s"
                . ($forUpdate ? " FOR UPDATE" : ""),
            $userId->value(),
            $workId->value()
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    private function hydrate(object $row): PersonalReadingTruth
    {
        try {
            return new PersonalReadingTruth(
                new UserId((string) $row->user_id),
                new WorkId((string) $row->work_id),
                PersonalReadingTruthState::from((string) $row->truth_state),
                new PersonalReadingTruthVersion((int) $row->truth_version),
                $this->instant($row->created_at),
                $this->instant($row->updated_at)
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Personal Reading Truth is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    private function date(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone("UTC"))->format(self::DATE_FORMAT);
    }

    private function instant(mixed $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            "!" . self::DATE_FORMAT,
            (string) $value,
            new DateTimeZone("UTC")
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($errors !== false && ($errors["warning_count"] > 0 || $errors["error_count"] > 0))
        ) {
            throw new PersistenceException(
                "Stored Personal Reading Truth time is invalid.",
                failureReason: FailureReason::PersistenceReadFailed
            );
        }

        return $date;
    }

    private function assertTransactionActive(): void
    {
        if ($this->connection->isTransactionActive() !== true) {
            throw new TransactionException(
                "Personal Reading Truth mutation requires an active transaction.",
                FailureReason::TransactionBeginFailed
            );
        }
    }
}
