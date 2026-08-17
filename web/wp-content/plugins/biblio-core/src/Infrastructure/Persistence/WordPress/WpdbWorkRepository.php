<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Catalog\WorkRepository;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Throwable;
use wpdb;

final readonly class WpdbWorkRepository implements WorkRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
    }

    public function add(Work $work): void
    {
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $this->database->insert(
                $this->tableNames->works(),
                [
                    "work_id" => $work->id()->value(),
                    "work_title" => $work->title(),
                ],
                ["%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result !== 1) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist Work.",
                $this->database->last_error
            );
        }
    }

    public function find(WorkId $workId): ?Work
    {
        $table = $this->tableNames->works();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT work_id, work_title FROM `{$table}` WHERE work_id = %s",
            $workId->value()
        ));

        if ($row === null) {
            return null;
        }

        try {
            return new Work(
                new WorkId($row->work_id),
                $row->work_title
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Work data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }
}
