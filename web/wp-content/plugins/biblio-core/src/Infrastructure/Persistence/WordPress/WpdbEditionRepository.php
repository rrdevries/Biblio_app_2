<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\CatalogRecordAlreadyExists;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\WritableEditionRepository;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Throwable;
use wpdb;

final readonly class WpdbEditionRepository implements WritableEditionRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
    }

    public function add(Edition $edition): void
    {
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $this->database->insert(
                $this->tableNames->editions(),
                [
                    "edition_id" => $edition->id()->value(),
                    "work_id" => $edition->workId()->value(),
                ],
                ["%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result !== 1) {
            $conflict = WpdbErrorTranslator::conflict(
                $this->database->last_error
            );

            if ($conflict?->constraintName() === "PRIMARY") {
                throw new CatalogRecordAlreadyExists(
                    WpdbErrorTranslator::diagnostic(
                        "Edition insert",
                        $this->database->last_error
                    )
                );
            }

            throw WpdbErrorTranslator::writeFailure(
                "Could not persist Edition.",
                $this->database->last_error
            );
        }
    }

    public function find(EditionId $editionId): ?Edition
    {
        $table = $this->tableNames->editions();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT edition_id, work_id FROM `{$table}` WHERE edition_id = %s",
            $editionId->value()
        ));

        if ($row === null) {
            return null;
        }

        try {
            return new Edition(
                new EditionId($row->edition_id),
                new WorkId($row->work_id)
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Edition data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }
}
