<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\Classification\{ClassificationNormalizedName,ClassificationSeedKey,ClassificationTermName,ClassificationTermStatus,LibraryBookType,LibraryBookTypeId,LibraryCatalogClassification,LibraryCatalogSelection,LibraryClassificationReadRepository,LibraryGenre,LibraryGenreId,LibrarySubject,LibrarySubjectId};
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use Throwable;
use wpdb;

final readonly class WpdbLibraryClassificationReadRepository implements LibraryClassificationReadRepository
{
    public function __construct(private wpdb $database, private CoreTableNames $tables)
    {
    }

    public function activeBookTypes(LibraryId $libraryId): array
    {
        $rows = $this->activeTerms($this->tables->libraryBookTypes(), 'book_type_id', $libraryId);
        try {
            return array_map(
                static fn (object $row): LibraryBookType => new LibraryBookType(
                    new LibraryId((string) $row->library_id),
                    new LibraryBookTypeId((string) $row->term_id),
                    new ClassificationTermName((string) $row->display_name),
                    new ClassificationNormalizedName((string) $row->normalized_name),
                    ClassificationTermStatus::from((string) $row->term_status),
                    $row->seed_key === null ? null : new ClassificationSeedKey((string) $row->seed_key)
                ),
                $rows
            );
        } catch (Throwable $exception) {
            throw $this->invalidStoredData('Book Type', $exception);
        }
    }

    public function activeGenres(LibraryId $libraryId): array
    {
        $rows = $this->activeTerms($this->tables->libraryGenres(), 'genre_id', $libraryId);
        try {
            return array_map(
                static fn (object $row): LibraryGenre => new LibraryGenre(
                    new LibraryId((string) $row->library_id),
                    new LibraryGenreId((string) $row->term_id),
                    new ClassificationTermName((string) $row->display_name),
                    new ClassificationNormalizedName((string) $row->normalized_name),
                    ClassificationTermStatus::from((string) $row->term_status),
                    $row->seed_key === null ? null : new ClassificationSeedKey((string) $row->seed_key)
                ),
                $rows
            );
        } catch (Throwable $exception) {
            throw $this->invalidStoredData('Genre', $exception);
        }
    }

    public function activeSubjects(LibraryId $libraryId): array
    {
        $rows = $this->activeTerms($this->tables->librarySubjects(), 'subject_id', $libraryId);
        try {
            return array_map(
                static fn (object $row): LibrarySubject => new LibrarySubject(
                    new LibraryId((string) $row->library_id),
                    new LibrarySubjectId((string) $row->term_id),
                    new ClassificationTermName((string) $row->display_name),
                    new ClassificationNormalizedName((string) $row->normalized_name),
                    ClassificationTermStatus::from((string) $row->term_status),
                    $row->seed_key === null ? null : new ClassificationSeedKey((string) $row->seed_key)
                ),
                $rows
            );
        } catch (Throwable $exception) {
            throw $this->invalidStoredData('Subject', $exception);
        }
    }

    public function classificationsForWorks(LibraryId $libraryId, array $workIds): array
    {
        $result = [];
        foreach ($workIds as $workId) {
            $result[$workId->value()] = null;
        }
        if ($workIds === []) {
            return $result;
        }

        $workValues = array_map(static fn (WorkId $id): string => $id->value(), $workIds);
        $placeholders = implode(',', array_fill(0, count($workValues), '%s'));
        $contexts = $this->database->get_results($this->database->prepare(
            "SELECT work_id,book_type_id FROM `{$this->tables->libraryCatalogContexts()}` "
                . "WHERE library_id=%s AND work_id IN ({$placeholders}) ORDER BY work_id",
            $libraryId->value(),
            ...$workValues
        ));
        $genres = $this->junctions($this->tables->libraryCatalogContextGenres(), 'genre_id', $libraryId, $workValues);
        $subjects = $this->junctions($this->tables->libraryCatalogContextSubjects(), 'subject_id', $libraryId, $workValues);

        try {
            foreach ($contexts as $row) {
                $workKey = (string) $row->work_id;
                $result[$workKey] = new LibraryCatalogSelection(
                    new LibraryBookTypeId((string) $row->book_type_id),
                    array_map(static fn (string $id): LibraryGenreId => new LibraryGenreId($id), $genres[$workKey] ?? []),
                    array_map(static fn (string $id): LibrarySubjectId => new LibrarySubjectId($id), $subjects[$workKey] ?? [])
                );
            }
            return $result;
        } catch (Throwable $exception) {
            throw $this->invalidStoredData('Catalog Context', $exception);
        }
    }

    public function assignedClassificationsForWorks(
        LibraryId $libraryId,
        array $workIds
    ): array {
        $result = [];
        foreach ($workIds as $workId) {
            $result[$workId->value()] = null;
        }
        if ($workIds === []) {
            return $result;
        }

        $workValues = array_map(
            static fn (WorkId $id): string => $id->value(),
            $workIds
        );
        $placeholders = implode(',', array_fill(0, count($workValues), '%s'));
        $libraryValue = $libraryId->value();
        $bookTypes = $this->database->get_results($this->database->prepare(
            "SELECT c.work_id,t.library_id,t.book_type_id term_id,"
                . "t.display_name,t.normalized_name,t.term_status,t.seed_key "
                . "FROM `{$this->tables->libraryCatalogContexts()}` c "
                . "INNER JOIN `{$this->tables->libraryBookTypes()}` t "
                . "ON t.library_id=c.library_id "
                . "AND t.book_type_id=c.book_type_id "
                . "WHERE c.library_id=%s AND c.work_id IN ({$placeholders}) "
                . "ORDER BY c.work_id",
            $libraryValue,
            ...$workValues
        ));
        $genres = $this->assignedTerms(
            $this->tables->libraryCatalogContextGenres(),
            $this->tables->libraryGenres(),
            'genre_id',
            $libraryId,
            $workValues
        );
        $subjects = $this->assignedTerms(
            $this->tables->libraryCatalogContextSubjects(),
            $this->tables->librarySubjects(),
            'subject_id',
            $libraryId,
            $workValues
        );

        try {
            foreach ($bookTypes as $row) {
                $workKey = (string) $row->work_id;
                $result[$workKey] = new LibraryCatalogClassification(
                    $this->hydrateBookType($row),
                    array_map($this->hydrateGenre(...), $genres[$workKey] ?? []),
                    array_map($this->hydrateSubject(...), $subjects[$workKey] ?? [])
                );
            }
            return $result;
        } catch (Throwable $exception) {
            throw $this->invalidStoredData('assigned Catalog Context', $exception);
        }
    }

    /** @return list<object> */
    private function activeTerms(string $table, string $idColumn, LibraryId $libraryId): array
    {
        return $this->database->get_results($this->database->prepare(
            "SELECT library_id,`{$idColumn}` term_id,display_name,normalized_name,term_status,seed_key "
                . "FROM `{$table}` WHERE library_id=%s AND term_status='active' "
                . "ORDER BY normalized_name,`{$idColumn}`",
            $libraryId->value()
        ));
    }

    /**
     * @param list<string> $workIds
     * @return array<string, list<string>>
     */
    private function junctions(string $table, string $idColumn, LibraryId $libraryId, array $workIds): array
    {
        $result = [];
        $placeholders = implode(',', array_fill(0, count($workIds), '%s'));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT work_id,`{$idColumn}` term_id FROM `{$table}` "
                . "WHERE library_id=%s AND work_id IN ({$placeholders}) ORDER BY work_id,`{$idColumn}`",
            $libraryId->value(),
            ...$workIds
        ));
        foreach ($rows as $row) {
            $result[(string) $row->work_id][] = (string) $row->term_id;
        }
        return $result;
    }

    /**
     * @param list<string> $workIds
     * @return array<string, list<object>>
     */
    private function assignedTerms(
        string $junctionTable,
        string $termTable,
        string $idColumn,
        LibraryId $libraryId,
        array $workIds
    ): array {
        $result = [];
        $placeholders = implode(',', array_fill(0, count($workIds), '%s'));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT j.work_id,t.library_id,t.`{$idColumn}` term_id,"
                . "t.display_name,t.normalized_name,t.term_status,t.seed_key "
                . "FROM `{$junctionTable}` j "
                . "INNER JOIN `{$termTable}` t ON t.library_id=j.library_id "
                . "AND t.`{$idColumn}`=j.`{$idColumn}` "
                . "WHERE j.library_id=%s AND j.work_id IN ({$placeholders}) "
                . "ORDER BY j.work_id,t.normalized_name,t.`{$idColumn}`",
            $libraryId->value(),
            ...$workIds
        ));
        foreach ($rows as $row) {
            $result[(string) $row->work_id][] = $row;
        }
        return $result;
    }

    private function hydrateBookType(object $row): LibraryBookType
    {
        return new LibraryBookType(
            new LibraryId((string) $row->library_id),
            new LibraryBookTypeId((string) $row->term_id),
            new ClassificationTermName((string) $row->display_name),
            new ClassificationNormalizedName((string) $row->normalized_name),
            ClassificationTermStatus::from((string) $row->term_status),
            $row->seed_key === null
                ? null
                : new ClassificationSeedKey((string) $row->seed_key)
        );
    }

    private function hydrateGenre(object $row): LibraryGenre
    {
        return new LibraryGenre(
            new LibraryId((string) $row->library_id),
            new LibraryGenreId((string) $row->term_id),
            new ClassificationTermName((string) $row->display_name),
            new ClassificationNormalizedName((string) $row->normalized_name),
            ClassificationTermStatus::from((string) $row->term_status),
            $row->seed_key === null
                ? null
                : new ClassificationSeedKey((string) $row->seed_key)
        );
    }

    private function hydrateSubject(object $row): LibrarySubject
    {
        return new LibrarySubject(
            new LibraryId((string) $row->library_id),
            new LibrarySubjectId((string) $row->term_id),
            new ClassificationTermName((string) $row->display_name),
            new ClassificationNormalizedName((string) $row->normalized_name),
            ClassificationTermStatus::from((string) $row->term_status),
            $row->seed_key === null
                ? null
                : new ClassificationSeedKey((string) $row->seed_key)
        );
    }

    private function invalidStoredData(string $type, Throwable $exception): PersistenceException
    {
        return new PersistenceException(
            "Stored Library {$type} read data is invalid.",
            0,
            $exception,
            FailureReason::PersistenceReadFailed
        );
    }
}
