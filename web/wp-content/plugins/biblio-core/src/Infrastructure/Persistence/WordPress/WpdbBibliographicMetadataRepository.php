<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\AlternateWorkTitle;
use Biblio\Core\Catalog\CatalogRecordAlreadyExists;
use Biblio\Core\Catalog\ContainmentPosition;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\EditionIsbnMetadata;
use Biblio\Core\Catalog\Isbn;
use Biblio\Core\Catalog\Isbn10;
use Biblio\Core\Catalog\Isbn13;
use Biblio\Core\Catalog\IsbnType;
use Biblio\Core\Catalog\WorkContainment;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Catalog\WritableBibliographicMetadataRepository;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Throwable;
use wpdb;

final readonly class WpdbBibliographicMetadataRepository implements
    WritableBibliographicMetadataRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
    }

    public function addAlternateTitle(AlternateWorkTitle $title): void
    {
        $this->insertRelationship(
            $this->tables->workAlternateTitles(),
            [
                "work_id" => $title->workId()->value(),
                "alternate_title" => $title->value(),
                "normalized_title" => $title->normalizedKey(),
            ],
            ["%s", "%s", "%s"],
            "Alternate Work title insert"
        );
    }

    public function addContainment(WorkContainment $containment): void
    {
        if ($this->wouldCreateCycle($containment)) {
            throw new ValidationException(
                "Work containment must not contain a cycle."
            );
        }

        $this->insertRelationship(
            $this->tables->workContainments(),
            [
                "parent_work_id" => $containment->parentWorkId()->value(),
                "contained_work_id" => $containment
                    ->containedWorkId()->value(),
                "contained_position" => $containment->position()->value(),
            ],
            ["%s", "%s", "%d"],
            "Work containment insert"
        );
    }

    public function alternateTitlesForWorks(array $workIds): array
    {
        $result = [];
        foreach ($workIds as $workId) {
            $result[$workId->value()] = [];
        }

        if ($workIds === []) {
            return $result;
        }

        $table = $this->tables->workAlternateTitles();
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT work_id, alternate_title FROM `{$table}` "
                . "WHERE work_id IN ("
                . $this->placeholders(count($workIds)) . ") "
                . "ORDER BY work_id, normalized_title, alternate_title",
            ...$this->workIdValues($workIds)
        ));

        try {
            foreach ($rows as $row) {
                $workId = new WorkId((string) $row->work_id);
                $result[$workId->value()][] = new AlternateWorkTitle(
                    $workId,
                    (string) $row->alternate_title
                );
            }

            return $result;
        } catch (Throwable $exception) {
            throw $this->readFailure(
                "Stored alternate Work title data is invalid.",
                $exception
            );
        }
    }

    public function editionsForWorks(array $workIds): array
    {
        $result = [];
        foreach ($workIds as $workId) {
            $result[$workId->value()] = [];
        }

        if ($workIds === []) {
            return $result;
        }

        $table = $this->tables->editions();
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT edition_id, work_id, isbn_10, isbn_13, "
                . "explicitly_no_isbn FROM `{$table}` WHERE work_id IN ("
                . $this->placeholders(count($workIds)) . ") "
                . "ORDER BY work_id, edition_id",
            ...$this->workIdValues($workIds)
        ));

        try {
            foreach ($rows as $row) {
                $edition = $this->hydrateEdition($row);
                $result[$edition->workId()->value()][] = $edition;
            }

            return $result;
        } catch (Throwable $exception) {
            throw $this->readFailure(
                "Stored Edition ISBN data is invalid.",
                $exception
            );
        }
    }

    public function editionsForIsbns(array $isbns): array
    {
        $result = [];
        $isbn10Values = [];
        $isbn13Values = [];

        foreach ($isbns as $isbn) {
            $result[$isbn->value()] = [];

            if ($isbn->type() === IsbnType::Isbn10) {
                $isbn10Values[] = $isbn->value();
            } else {
                $isbn13Values[] = $isbn->value();
            }
        }

        if ($isbns === []) {
            return $result;
        }

        $predicates = [];
        $values = [];
        if ($isbn10Values !== []) {
            $predicates[] = "isbn_10 IN ("
                . $this->placeholders(count($isbn10Values)) . ")";
            array_push($values, ...$isbn10Values);
        }
        if ($isbn13Values !== []) {
            $predicates[] = "isbn_13 IN ("
                . $this->placeholders(count($isbn13Values)) . ")";
            array_push($values, ...$isbn13Values);
        }

        $table = $this->tables->editions();
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT edition_id, work_id, isbn_10, isbn_13, "
                . "explicitly_no_isbn FROM `{$table}` WHERE "
                . implode(" OR ", $predicates)
                . " ORDER BY edition_id",
            ...$values
        ));

        try {
            foreach ($rows as $row) {
                $edition = $this->hydrateEdition($row);
                foreach ([$row->isbn_10, $row->isbn_13] as $storedIsbn) {
                    if (
                        is_string($storedIsbn)
                        && array_key_exists($storedIsbn, $result)
                    ) {
                        $result[$storedIsbn][] = $edition;
                    }
                }
            }

            return $result;
        } catch (Throwable $exception) {
            throw $this->readFailure(
                "Stored Edition ISBN data is invalid.",
                $exception
            );
        }
    }

    public function containedWorksForParents(array $parentWorkIds): array
    {
        return $this->containments(
            "parent_work_id",
            $parentWorkIds,
            "parent_work_id, contained_position, contained_work_id"
        );
    }

    public function parentWorksForContained(array $containedWorkIds): array
    {
        return $this->containments(
            "contained_work_id",
            $containedWorkIds,
            "contained_work_id, parent_work_id"
        );
    }

    /**
     * @param array<string, string|int|null> $data
     * @param list<string> $formats
     */
    private function insertRelationship(
        string $table,
        array $data,
        array $formats,
        string $operation
    ): void {
        $previous = $this->database->suppress_errors(true);
        try {
            $result = $this->database->insert($table, $data, $formats);
        } finally {
            $this->database->suppress_errors($previous);
        }

        if ($result === 1) {
            return;
        }

        if (WpdbErrorTranslator::conflict($this->database->last_error) !== null) {
            throw new CatalogRecordAlreadyExists(
                WpdbErrorTranslator::diagnostic(
                    $operation,
                    $this->database->last_error
                )
            );
        }

        throw WpdbErrorTranslator::writeFailure(
            "Could not persist bibliographic metadata relationship.",
            $this->database->last_error
        );
    }

    /**
     * @param list<WorkId> $ids
     * @return array<string, list<WorkContainment>>
     */
    private function containments(
        string $column,
        array $ids,
        string $order
    ): array {
        $result = [];
        foreach ($ids as $id) {
            $result[$id->value()] = [];
        }

        if ($ids === []) {
            return $result;
        }

        $table = $this->tables->workContainments();
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT parent_work_id, contained_work_id, contained_position "
                . "FROM `{$table}` WHERE {$column} IN ("
                . $this->placeholders(count($ids)) . ") ORDER BY {$order}",
            ...$this->workIdValues($ids)
        ));

        try {
            foreach ($rows as $row) {
                $result[(string) $row->{$column}][] = new WorkContainment(
                    new WorkId((string) $row->parent_work_id),
                    new WorkId((string) $row->contained_work_id),
                    new ContainmentPosition(
                        (int) $row->contained_position
                    )
                );
            }

            return $result;
        } catch (Throwable $exception) {
            throw $this->readFailure(
                "Stored Work containment data is invalid.",
                $exception
            );
        }
    }

    private function hydrateEdition(object $row): Edition
    {
        $isbn10 = $row->isbn_10 === null
            ? null
            : new Isbn10((string) $row->isbn_10);
        $isbn13 = $row->isbn_13 === null
            ? null
            : new Isbn13((string) $row->isbn_13);
        $metadata = (int) $row->explicitly_no_isbn === 1
            ? EditionIsbnMetadata::withoutIsbn()
            : ($isbn10 === null && $isbn13 === null
                ? EditionIsbnMetadata::unknown()
                : EditionIsbnMetadata::identified($isbn10, $isbn13));

        return new Edition(
            new EditionId((string) $row->edition_id),
            new WorkId((string) $row->work_id),
            $metadata
        );
    }

    private function wouldCreateCycle(WorkContainment $containment): bool
    {
        $table = $this->tables->workContainments();
        $result = $this->database->get_var($this->database->prepare(
            "WITH RECURSIVE descendants (work_id) AS ("
                . "SELECT contained_work_id FROM `{$table}` "
                . "WHERE parent_work_id = %s UNION DISTINCT "
                . "SELECT c.contained_work_id FROM `{$table}` c "
                . "INNER JOIN descendants d ON c.parent_work_id = d.work_id"
                . ") SELECT work_id FROM descendants WHERE work_id = %s LIMIT 1",
            $containment->containedWorkId()->value(),
            $containment->parentWorkId()->value()
        ));

        if ($this->database->last_error !== "") {
            throw $this->readFailure(
                "Could not validate Work containment cycle safety.",
                new PersistenceException(
                    $this->database->last_error,
                    0,
                    null,
                    FailureReason::PersistenceReadFailed
                )
            );
        }

        return is_string($result);
    }

    /**
     * @param list<WorkId> $workIds
     * @return list<string>
     */
    private function workIdValues(array $workIds): array
    {
        return array_map(
            static fn (WorkId $workId): string => $workId->value(),
            $workIds
        );
    }

    private function placeholders(int $count): string
    {
        return implode(",", array_fill(0, $count, "%s"));
    }

    private function readFailure(
        string $message,
        Throwable $exception
    ): PersistenceException {
        return new PersistenceException(
            $message,
            0,
            $exception,
            FailureReason::PersistenceReadFailed
        );
    }
}
