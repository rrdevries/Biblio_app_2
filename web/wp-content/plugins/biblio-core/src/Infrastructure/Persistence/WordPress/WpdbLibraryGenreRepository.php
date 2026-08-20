<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\Classification\ClassificationNormalizedName;
use Biblio\Core\Catalog\Classification\ClassificationSeedKey;
use Biblio\Core\Catalog\Classification\ClassificationTermConflict;
use Biblio\Core\Catalog\Classification\ClassificationTermConflictType;
use Biblio\Core\Catalog\Classification\ClassificationTermName;
use Biblio\Core\Catalog\Classification\ClassificationTermStatus;
use Biblio\Core\Catalog\Classification\LibraryGenre;
use Biblio\Core\Catalog\Classification\LibraryGenreId;
use Biblio\Core\Catalog\Classification\WritableLibraryGenreRepository;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use Throwable;
use wpdb;

final readonly class WpdbLibraryGenreRepository implements
    WritableLibraryGenreRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
    }

    public function add(LibraryGenre $term): void
    {
        $this->write(fn (): int|false => $this->database->insert(
            $this->tableNames->libraryGenres(),
            [
                "library_id" => $term->libraryId()->value(),
                "genre_id" => $term->id()->value(),
                "display_name" => $term->name()->value(),
                "normalized_name" => $term->normalizedName()->value(),
                "term_status" => $term->status()->value,
                "seed_key" => $term->seedKey()?->value(),
            ],
            ["%s", "%s", "%s", "%s", "%s", "%s"]
        ), "Could not persist Library Genre.");
    }

    public function rename(
        LibraryId $libraryId,
        LibraryGenreId $id,
        ClassificationTermName $name,
        ClassificationNormalizedName $normalizedName
    ): void {
        $this->write(fn (): int|false => $this->database->update(
            $this->tableNames->libraryGenres(),
            [
                "display_name" => $name->value(),
                "normalized_name" => $normalizedName->value(),
            ],
            [
                "library_id" => $libraryId->value(),
                "genre_id" => $id->value(),
            ],
            ["%s", "%s"],
            ["%s", "%s"]
        ), "Could not rename Library Genre.");
    }

    public function changeStatus(
        LibraryId $libraryId,
        LibraryGenreId $id,
        ClassificationTermStatus $status
    ): void {
        $this->write(fn (): int|false => $this->database->update(
            $this->tableNames->libraryGenres(),
            ["term_status" => $status->value],
            [
                "library_id" => $libraryId->value(),
                "genre_id" => $id->value(),
            ],
            ["%s"],
            ["%s", "%s"]
        ), "Could not change Library Genre status.");
    }

    public function adoptSeedKey(
        LibraryId $libraryId,
        LibraryGenreId $id,
        ClassificationSeedKey $seedKey
    ): bool {
        $table = $this->tableNames->libraryGenres();

        return $this->write(fn (): int|false => $this->database->query(
            $this->database->prepare(
                "UPDATE `{$table}` SET seed_key = %s "
                . "WHERE library_id = %s AND genre_id = %s "
                . "AND seed_key IS NULL",
                $seedKey->value(),
                $libraryId->value(),
                $id->value()
            )
        ), "Could not adopt Library Genre seed key.", true) === 1;
    }

    public function find(
        LibraryId $libraryId,
        LibraryGenreId $id
    ): ?LibraryGenre {
        return $this->findBy(
            "genre_id = %s",
            [$libraryId->value(), $id->value()]
        );
    }

    public function findForUpdate(
        LibraryId $libraryId,
        LibraryGenreId $id
    ): ?LibraryGenre {
        return $this->findBy(
            "genre_id = %s",
            [$libraryId->value(), $id->value()],
            true
        );
    }

    public function findByNormalizedName(
        LibraryId $libraryId,
        ClassificationNormalizedName $name
    ): ?LibraryGenre {
        return $this->findBy(
            "normalized_name = %s",
            [$libraryId->value(), $name->value()]
        );
    }

    public function findBySeedKey(
        LibraryId $libraryId,
        ClassificationSeedKey $seedKey
    ): ?LibraryGenre {
        return $this->findBy(
            "seed_key = %s",
            [$libraryId->value(), $seedKey->value()]
        );
    }

    /** @param list<string> $parameters */
    private function findBy(
        string $criterion,
        array $parameters,
        bool $forUpdate = false
    ): ?LibraryGenre {
        $table = $this->tableNames->libraryGenres();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT library_id, genre_id, display_name, normalized_name, "
            . "term_status, seed_key FROM `{$table}` "
            . "WHERE library_id = %s AND {$criterion}"
            . ($forUpdate ? " FOR UPDATE" : ""),
            ...$parameters
        ));

        if ($row === null) {
            return null;
        }

        try {
            return new LibraryGenre(
                new LibraryId((string) $row->library_id),
                new LibraryGenreId((string) $row->genre_id),
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
                "Stored Library Genre data is invalid.",
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

        $error = $this->database->last_error;
        $constraint = WpdbErrorTranslator::conflict($error)?->constraintName();
        $type = match ($constraint) {
            "PRIMARY" => ClassificationTermConflictType::Identifier,
            "genres_by_normalized_name" =>
                ClassificationTermConflictType::NormalizedName,
            "genres_by_seed_key" => ClassificationTermConflictType::SeedKey,
            default => null,
        };

        if ($type !== null) {
            throw new ClassificationTermConflict(
                $type,
                WpdbErrorTranslator::diagnostic("Genre write", $error)
            );
        }

        throw WpdbErrorTranslator::writeFailure($failureMessage, $error);
    }
}
