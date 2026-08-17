<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Borrowing\ExternalLoan;
use Biblio\Core\Borrowing\WritableExternalLoanRepository;
use DateTimeImmutable;
use DateTimeZone;
use wpdb;

final readonly class WpdbExternalLoanWriter implements
    WritableExternalLoanRepository
{
    private const DATABASE_DATE_FORMAT = "Y-m-d H:i:s.u";

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
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
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist External Loan.",
                $this->database->last_error
            );
        }
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date
            ->setTimezone(new DateTimeZone("UTC"))
            ->format(self::DATABASE_DATE_FORMAT);
    }
}
