<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Borrowing\ExternalLoan;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Borrowing\ExternalLoanRepository;
use Biblio\Core\Borrowing\ExternalLoanStatus;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use wpdb;

final readonly class WpdbExternalLoanRepository implements
    ExternalLoanRepository
{
    private const DATABASE_DATE_FORMAT = "Y-m-d H:i:s.u";

    public function __construct(
        private wpdb $database,
        private LibraryTableNames $tableNames
    ) {
    }

    public function add(ExternalLoan $externalLoan): void
    {
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $this->database->insert(
                $this->tableNames->externalLoans(),
                [
                    "external_loan_id" => $externalLoan->id()->value(),
                    "user_id" => $externalLoan->userId()->value(),
                    "work_id" => $externalLoan->workId()->value(),
                    "loan_status" => $externalLoan->status()->value,
                    "borrowed_at" => $this->formatDate(
                        $externalLoan->borrowedAt()
                    ),
                    "due_at" => $externalLoan->dueAt() === null
                        ? null
                        : $this->formatDate($externalLoan->dueAt()),
                ],
                ["%s", "%s", "%s", "%s", "%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result !== 1) {
            throw new PersistenceException(
                "Could not persist External Loan: "
                . $this->database->last_error
            );
        }
    }

    public function findForUser(
        ExternalLoanId $externalLoanId,
        UserId $userId
    ): ?ExternalLoan {
        $table = $this->tableNames->externalLoans();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT external_loan_id, user_id, work_id, loan_status, "
            . "borrowed_at, due_at FROM `{$table}` "
            . "WHERE external_loan_id = %s AND user_id = %s",
            $externalLoanId->value(),
            $userId->value()
        ));

        if ($row === null) {
            return null;
        }

        try {
            return new ExternalLoan(
                new ExternalLoanId($row->external_loan_id),
                new UserId($row->user_id),
                new WorkId($row->work_id),
                ExternalLoanStatus::from($row->loan_status),
                $this->hydrateDate($row->borrowed_at),
                $row->due_at === null
                    ? null
                    : $this->hydrateDate($row->due_at)
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored External Loan data is invalid.",
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
                "Stored External Loan date is invalid."
            );
        }

        return $date;
    }
}
