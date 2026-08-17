<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Reading\ActiveReadingRoundAlreadyExists;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundRepository;
use Biblio\Core\Reading\ReadingRoundStatus;
use Biblio\Core\Reading\ReadingSource;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use wpdb;

final readonly class WpdbReadingRoundRepository implements
    ReadingRoundRepository
{
    private const DATABASE_DATE_FORMAT = "Y-m-d H:i:s.u";

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
    }

    public function addForUser(
        UserId $authenticatedUserId,
        ReadingRound $readingRound
    ): void {
        if (!$authenticatedUserId->equals($readingRound->userId())) {
            throw new PersistenceException(
                "Cannot persist a Reading Round for another user."
            );
        }

        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $this->database->insert(
                $this->tableNames->readingRounds(),
                [
                    "reading_round_id" => $readingRound->id()->value(),
                    "user_id" => $readingRound->userId()->value(),
                    "work_id" => $readingRound->workId()->value(),
                    "item_id" => $readingRound->source()->itemId()?->value(),
                    "external_loan_id" => $readingRound
                        ->source()->externalLoanId()?->value(),
                    "round_status" => $readingRound->status()->value,
                    "started_at" => $this->formatDate(
                        $readingRound->startedAt()
                    ),
                ],
                ["%s", "%s", "%s", "%s", "%s", "%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result === 1) {
            return;
        }

        if (
            str_contains(
                $this->database->last_error,
                "one_active_item_round_per_user"
            )
            || str_contains(
                $this->database->last_error,
                "one_active_external_round_per_user"
            )
        ) {
            throw new ActiveReadingRoundAlreadyExists();
        }

        throw new PersistenceException(
            "Could not persist Reading Round: "
            . $this->database->last_error
        );
    }

    public function findForUser(
        ReadingRoundId $readingRoundId,
        UserId $userId
    ): ?ReadingRound {
        $table = $this->tableNames->readingRounds();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT reading_round_id, user_id, work_id, item_id, "
            . "external_loan_id, round_status, started_at "
            . "FROM `{$table}` WHERE reading_round_id = %s AND user_id = %s",
            $readingRoundId->value(),
            $userId->value()
        ));

        return $row === null ? null : $this->hydrate($row);
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
            "SELECT reading_round_id, user_id, work_id, item_id, "
            . "external_loan_id, round_status, started_at "
            . "FROM `{$table}` WHERE user_id = %s "
            . "AND round_status = 'active' AND `{$sourceColumn}` = %s",
            $userId->value(),
            $sourceId
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    private function hydrate(object $row): ReadingRound
    {
        try {
            $source = $row->item_id !== null
                ? ReadingSource::libraryItem(new ItemId($row->item_id))
                : ReadingSource::externalLoan(
                    new ExternalLoanId($row->external_loan_id)
                );

            return new ReadingRound(
                new ReadingRoundId($row->reading_round_id),
                new UserId($row->user_id),
                new WorkId($row->work_id),
                $source,
                ReadingRoundStatus::from($row->round_status),
                $this->hydrateDate($row->started_at)
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Reading Round data is invalid.",
                0,
                $exception
            );
        }
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date
            ->setTimezone(new DateTimeZone("UTC"))
            ->format(self::DATABASE_DATE_FORMAT);
    }

    private function hydrateDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            "!" . self::DATABASE_DATE_FORMAT,
            $value,
            new DateTimeZone("UTC")
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || (
                $errors !== false
                && ($errors["warning_count"] > 0 || $errors["error_count"] > 0)
            )
        ) {
            throw new PersistenceException(
                "Stored Reading Round date is invalid."
            );
        }

        return $date;
    }
}
