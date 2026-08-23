<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\LibraryWorkRepresentationRepository;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use Throwable;
use wpdb;

final readonly class WpdbLibraryWorkRepresentationRepository implements
    LibraryWorkRepresentationRepository
{
    public function hasActiveWorkRepresentation(
        LibraryId $libraryId,
        WorkId $workId
    ): bool {
        $items = $this->tableNames->items();
        $editions = $this->tableNames->editions();
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$items}` i INNER JOIN `{$editions}` e "
            . "ON e.edition_id=i.edition_id WHERE i.library_id=%s "
            . "AND i.item_status='active' AND e.work_id=%s",
            $libraryId->value(),
            $workId->value()
        )) > 0;
    }
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
    }

    public function findRepresentedWork(
        LibraryId $libraryId,
        WorkId $workId
    ): ?Work {
        $items = $this->tableNames->items();
        $editions = $this->tableNames->editions();
        $works = $this->tableNames->works();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT DISTINCT w.work_id, w.work_title "
            . "FROM `{$items}` i "
            . "INNER JOIN `{$editions}` e ON e.edition_id = i.edition_id "
            . "INNER JOIN `{$works}` w ON w.work_id = e.work_id "
            . "WHERE i.library_id = %s AND w.work_id = %s LIMIT 1",
            $libraryId->value(),
            $workId->value()
        ));

        if ($row === null) {
            return null;
        }

        try {
            return new Work(
                new WorkId((string) $row->work_id),
                (string) $row->work_title
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Library Work representation is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }
}
