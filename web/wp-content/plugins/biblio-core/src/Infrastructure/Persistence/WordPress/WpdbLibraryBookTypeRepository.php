<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\Classification\ClassificationNormalizedName;
use Biblio\Core\Catalog\Classification\ClassificationSeedKey;
use Biblio\Core\Catalog\Classification\ClassificationTermConflict;
use Biblio\Core\Catalog\Classification\ClassificationTermConflictType;
use Biblio\Core\Catalog\Classification\ClassificationTermName;
use Biblio\Core\Catalog\Classification\ClassificationTermStatus;
use Biblio\Core\Catalog\Classification\LibraryBookType;
use Biblio\Core\Catalog\Classification\LibraryBookTypeId;
use Biblio\Core\Catalog\Classification\WritableLibraryBookTypeRepository;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use Throwable;
use wpdb;

final readonly class WpdbLibraryBookTypeRepository implements
    WritableLibraryBookTypeRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
    }

    public function add(LibraryBookType $term): void
    {
        $this->write(function () use ($term): int|false {
            return $this->database->insert(
                $this->tableNames->libraryBookTypes(),
                [
                    "library_id" => $term->libraryId()->value(),
                    "book_type_id" => $term->id()->value(),
                    "display_name" => $term->name()->value(),
                    "normalized_name" => $term->normalizedName()->value(),
                    "term_status" => $term->status()->value,
                    "seed_key" => $term->seedKey()?->value(),
                ],
                ["%s", "%s", "%s", "%s", "%s", "%s"]
            );
        }, "Could not persist Library Book Type.");
    }

    public function rename(
        LibraryId $libraryId,
        LibraryBookTypeId $id,
        ClassificationTermName $name,
        ClassificationNormalizedName $normalizedName
    ): void {
        $this->write(function () use (
            $libraryId,
            $id,
            $name,
            $normalizedName
        ): int|false {
            return $this->database->update(
                $this->tableNames->libraryBookTypes(),
                [
                    "display_name" => $name->value(),
                    "normalized_name" => $normalizedName->value(),
                ],
                [
                    "library_id" => $libraryId->value(),
                    "book_type_id" => $id->value(),
                ],
                ["%s", "%s"],
                ["%s", "%s"]
            );
        }, "Could not rename Library Book Type.");
    }

    public function changeStatus(
        LibraryId $libraryId,
        LibraryBookTypeId $id,
        ClassificationTermStatus $status
    ): void {
        $this->write(fn (): int|false => $this->database->update(
            $this->tableNames->libraryBookTypes(),
            ["term_status" => $status->value],
            [
                "library_id" => $libraryId->value(),
                "book_type_id" => $id->value(),
            ],
            ["%s"],
            ["%s", "%s"]
        ), "Could not change Library Book Type status.");
    }

    public function adoptSeedKey(
        LibraryId $libraryId,
        LibraryBookTypeId $id,
        ClassificationSeedKey $seedKey
    ): bool {
        $table = $this->tableNames->libraryBookTypes();

        return $this->write(function () use (
            $table,
            $libraryId,
            $id,
            $seedKey
        ): int|false {
            return $this->database->query($this->database->prepare(
                "UPDATE `{$table}` SET seed_key = %s "
                . "WHERE library_id = %s AND book_type_id = %s "
                . "AND seed_key IS NULL",
                $seedKey->value(),
                $libraryId->value(),
                $id->value()
            ));
        }, "Could not adopt Library Book Type seed key.", true) === 1;
    }

    public function find(
        LibraryId $libraryId,
        LibraryBookTypeId $id
    ): ?LibraryBookType {
        return $this->findBy(
            "book_type_id = %s",
            [$libraryId->value(), $id->value()]
        );
    }

    public function findForUpdate(
        LibraryId $libraryId,
        LibraryBookTypeId $id
    ): ?LibraryBookType {
        return $this->findBy(
            "book_type_id = %s",
            [$libraryId->value(), $id->value()],
            true
        );
    }

    public function findByNormalizedName(
        LibraryId $libraryId,
        ClassificationNormalizedName $name
    ): ?LibraryBookType {
        return $this->findBy(
            "normalized_name = %s",
            [$libraryId->value(), $name->value()]
        );
    }

    public function findBySeedKey(
        LibraryId $libraryId,
        ClassificationSeedKey $seedKey
    ): ?LibraryBookType {
        return $this->findBy(
            "seed_key = %s",
            [$libraryId->value(), $seedKey->value()]
        );
    }

    /**
     * @param list<string> $parameters Library ID followed by criterion value.
     */
    private function findBy(
        string $criterion,
        array $parameters,
        bool $forUpdate = false
    ): ?LibraryBookType {
        $table = $this->tableNames->libraryBookTypes();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT library_id, book_type_id, display_name, normalized_name, "
            . "term_status, seed_key FROM `{$table}` "
            . "WHERE library_id = %s AND {$criterion}"
            . ($forUpdate ? " FOR UPDATE" : ""),
            ...$parameters
        ));

        if ($row === null) {
            return null;
        }

        try {
            return new LibraryBookType(
                new LibraryId((string) $row->library_id),
                new LibraryBookTypeId((string) $row->book_type_id),
                new ClassificationTermName((string) $row->display_name),
                new ClassificationNormalizedName(
                    (string) $row->normalized_name
                ),
                ClassificationTermStatus::from((string) $row->term_status),
                $row->seed_key === null
                    ? null
                    : new ClassificationSeedKey((string) $row->seed_key)
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Library Book Type data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    /** @param callable(): (int|false) $operation */
    private function write(
        callable $operation,
        string $failureMessage,
        bool $allowNoMatch = false
    ): int {
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $operation();
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result === 1 || ($allowNoMatch && $result === 0)) {
            return $result;
        }

        $this->throwWriteFailure($failureMessage);
    }

    private function throwWriteFailure(string $message): never
    {
        $error = $this->database->last_error;
        $constraint = WpdbErrorTranslator::conflict($error)?->constraintName();
        $type = match ($constraint) {
            "PRIMARY" => ClassificationTermConflictType::Identifier,
            "book_types_by_normalized_name" =>
                ClassificationTermConflictType::NormalizedName,
            "book_types_by_seed_key" =>
                ClassificationTermConflictType::SeedKey,
            default => null,
        };

        if ($type !== null) {
            throw new ClassificationTermConflict(
                $type,
                WpdbErrorTranslator::diagnostic("Book Type write", $error)
            );
        }

        throw WpdbErrorTranslator::writeFailure($message, $error);
    }
}
