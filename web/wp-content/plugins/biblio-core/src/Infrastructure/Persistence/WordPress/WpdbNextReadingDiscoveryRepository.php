<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\NextReading\Read\{NextReadingDiscoveryRepository,NextReadingSourceOptionView};
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\{ItemId,WorkId};
use Biblio\Core\Exception\{FailureReason};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\{LibraryId,LibraryName};
use Throwable;
use wpdb;

final readonly class WpdbNextReadingDiscoveryRepository implements
    NextReadingDiscoveryRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
    }

    public function libraryItemOptions(WorkId $workId, array $libraryIds): array
    {
        if ($libraryIds === []) {
            return [];
        }

        $items = $this->tableNames->items();
        $editions = $this->tableNames->editions();
        $libraries = $this->tableNames->libraries();
        $placeholders = implode(", ", array_fill(0, count($libraryIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT i.item_id, i.library_id, l.library_name "
            . "FROM `{$items}` i "
            . "INNER JOIN `{$editions}` e ON e.edition_id = i.edition_id "
            . "INNER JOIN `{$libraries}` l ON l.library_id = i.library_id "
            . "WHERE e.work_id = %s AND i.item_status = 'active' "
            . "AND i.library_id IN ({$placeholders}) "
            . "ORDER BY l.library_name ASC, i.item_id ASC",
            $workId->value(),
            ...array_map(static fn (LibraryId $id): string => $id->value(), $libraryIds)
        ));

        return array_map($this->libraryItem(...), $rows);
    }

    public function externalLoanOptions(UserId $actorId, WorkId $workId): array
    {
        $loans = $this->tableNames->externalLoans();
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT external_loan_id FROM `{$loans}` "
            . "WHERE user_id = %s AND work_id = %s AND loan_status = 'active' "
            . "ORDER BY borrowed_at ASC, external_loan_id ASC",
            $actorId->value(),
            $workId->value()
        ));

        return array_map($this->externalLoan(...), $rows);
    }

    private function libraryItem(object $row): NextReadingSourceOptionView
    {
        try {
            $libraryName = new LibraryName((string) $row->library_name);

            return NextReadingSourceOptionView::libraryItem(
                new LibraryId((string) $row->library_id),
                new ItemId((string) $row->item_id),
                "Exemplaar uit " . $libraryName->value()
            );
        } catch (Throwable $exception) {
            throw $this->invalid("Stored Item discovery data is invalid.", $exception);
        }
    }

    private function externalLoan(object $row): NextReadingSourceOptionView
    {
        try {
            return NextReadingSourceOptionView::externalLoan(
                new ExternalLoanId((string) $row->external_loan_id),
                "Externe lening"
            );
        } catch (Throwable $exception) {
            throw $this->invalid("Stored loan discovery data is invalid.", $exception);
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
