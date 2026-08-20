<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Library\AdditionalPermissions;
use JsonException;
use RuntimeException;
use Throwable;
use wpdb;

final readonly class CoreSchema1001Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $healthChecker;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
        $this->healthChecker = new CoreSchemaHealthChecker(
            $database,
            $tableNames
        );
    }

    public function sourceVersion(): int
    {
        return 1000;
    }

    public function targetVersion(): int
    {
        return 1001;
    }

    public function assertPrecondition(): void
    {
        $baseline = $this->healthChecker->inspectForVersion(1000);

        if (!$baseline->isHealthy()) {
            throw new CoreSchemaHealthException($baseline);
        }

        $partial = $this->healthChecker
            ->inspectExistingSchema1001Additions();

        if (!$partial->isHealthy()) {
            throw new CoreSchemaHealthException($partial);
        }

        $this->assertPermissionPayloadsValid();
    }

    public function migrate(): void
    {
        foreach ($this->tableDefinitions() as $tableName => $sql) {
            if (!$this->tableExists($tableName)) {
                $this->execute($sql);
            }
        }

        $this->backfillManagerItemAddPermission();
    }

    public function assertPostcondition(): void
    {
        $health = $this->healthChecker->inspectForVersion(1001);

        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }

        foreach ($this->membershipRows() as $row) {
            $permissions = $this->decodePermissions(
                (string) $row->additional_permissions
            );

            if (
                (string) $row->management_role === "manager"
                && !$permissions->contains(
                    AdditionalPermissions::CATALOG_ITEM_ADD
                )
            ) {
                throw new RuntimeException(
                    "Manager permission backfill postcondition failed."
                );
            }
        }
    }

    /** @return array<string, string> */
    private function tableDefinitions(): array
    {
        $libraries = $this->tableNames->libraries();
        $works = $this->tableNames->works();
        $bookTypes = $this->tableNames->libraryBookTypes();
        $genres = $this->tableNames->libraryGenres();
        $subjects = $this->tableNames->librarySubjects();
        $contexts = $this->tableNames->libraryCatalogContexts();
        $contextGenres = $this->tableNames->libraryCatalogContextGenres();
        $contextSubjects = $this->tableNames->libraryCatalogContextSubjects();
        $activityEvents = $this->tableNames->libraryActivityEvents();
        $charsetCollate = $this->database->get_charset_collate();

        return [
            $bookTypes => "CREATE TABLE `{$bookTypes}` ("
                . "library_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "book_type_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "display_name VARCHAR(512) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "normalized_name VARCHAR(512) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "term_status VARCHAR(32) NOT NULL, "
                . "seed_key VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NULL, "
                . "PRIMARY KEY (library_id, book_type_id), "
                . "UNIQUE KEY book_types_by_normalized_name "
                . "(library_id, normalized_name), "
                . "UNIQUE KEY book_types_by_seed_key (library_id, seed_key), "
                . "FOREIGN KEY (library_id) REFERENCES `{$libraries}` "
                . "(library_id) ON UPDATE RESTRICT ON DELETE RESTRICT, "
                . "CONSTRAINT book_types_name_non_empty "
                . "CHECK (CHAR_LENGTH(TRIM(display_name)) > 0), "
                . "CONSTRAINT book_types_normalized_non_empty "
                . "CHECK (CHAR_LENGTH(TRIM(normalized_name)) > 0), "
                . "CONSTRAINT book_types_status_valid "
                . "CHECK (term_status IN ('active', 'inactive'))"
                . ") ENGINE=InnoDB {$charsetCollate}",
            $genres => "CREATE TABLE `{$genres}` ("
                . "library_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "genre_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "display_name VARCHAR(512) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "normalized_name VARCHAR(512) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "term_status VARCHAR(32) NOT NULL, "
                . "seed_key VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NULL, "
                . "PRIMARY KEY (library_id, genre_id), "
                . "UNIQUE KEY genres_by_normalized_name "
                . "(library_id, normalized_name), "
                . "UNIQUE KEY genres_by_seed_key (library_id, seed_key), "
                . "FOREIGN KEY (library_id) REFERENCES `{$libraries}` "
                . "(library_id) ON UPDATE RESTRICT ON DELETE RESTRICT, "
                . "CONSTRAINT genres_name_non_empty "
                . "CHECK (CHAR_LENGTH(TRIM(display_name)) > 0), "
                . "CONSTRAINT genres_normalized_non_empty "
                . "CHECK (CHAR_LENGTH(TRIM(normalized_name)) > 0), "
                . "CONSTRAINT genres_status_valid "
                . "CHECK (term_status IN ('active', 'inactive'))"
                . ") ENGINE=InnoDB {$charsetCollate}",
            $subjects => "CREATE TABLE `{$subjects}` ("
                . "library_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "subject_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "display_name VARCHAR(512) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "normalized_name VARCHAR(512) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "term_status VARCHAR(32) NOT NULL, "
                . "seed_key VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NULL, "
                . "PRIMARY KEY (library_id, subject_id), "
                . "UNIQUE KEY subjects_by_normalized_name "
                . "(library_id, normalized_name), "
                . "UNIQUE KEY subjects_by_seed_key (library_id, seed_key), "
                . "FOREIGN KEY (library_id) REFERENCES `{$libraries}` "
                . "(library_id) ON UPDATE RESTRICT ON DELETE RESTRICT, "
                . "CONSTRAINT subjects_name_non_empty "
                . "CHECK (CHAR_LENGTH(TRIM(display_name)) > 0), "
                . "CONSTRAINT subjects_normalized_non_empty "
                . "CHECK (CHAR_LENGTH(TRIM(normalized_name)) > 0), "
                . "CONSTRAINT subjects_status_valid "
                . "CHECK (term_status IN ('active', 'inactive'))"
                . ") ENGINE=InnoDB {$charsetCollate}",
            $contexts => "CREATE TABLE `{$contexts}` ("
                . "library_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "work_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "book_type_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "context_version BIGINT UNSIGNED NOT NULL, "
                . "PRIMARY KEY (library_id, work_id), "
                . "KEY catalog_contexts_by_work (work_id), "
                . "KEY catalog_contexts_by_book_type "
                . "(library_id, book_type_id), "
                . "FOREIGN KEY (library_id) REFERENCES `{$libraries}` "
                . "(library_id) ON UPDATE RESTRICT ON DELETE RESTRICT, "
                . "FOREIGN KEY (work_id) REFERENCES `{$works}` (work_id) "
                . "ON UPDATE RESTRICT ON DELETE RESTRICT, "
                . "FOREIGN KEY (library_id, book_type_id) "
                . "REFERENCES `{$bookTypes}` (library_id, book_type_id) "
                . "ON UPDATE RESTRICT ON DELETE RESTRICT, "
                . "CONSTRAINT catalog_context_version_valid "
                . "CHECK (context_version >= 1)"
                . ") ENGINE=InnoDB {$charsetCollate}",
            $contextGenres => "CREATE TABLE `{$contextGenres}` ("
                . "library_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "work_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "genre_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "PRIMARY KEY (library_id, work_id, genre_id), "
                . "KEY context_genres_by_genre (library_id, genre_id), "
                . "FOREIGN KEY (library_id, work_id) REFERENCES `{$contexts}` "
                . "(library_id, work_id) ON UPDATE RESTRICT ON DELETE RESTRICT, "
                . "FOREIGN KEY (library_id, genre_id) REFERENCES `{$genres}` "
                . "(library_id, genre_id) ON UPDATE RESTRICT ON DELETE RESTRICT"
                . ") ENGINE=InnoDB {$charsetCollate}",
            $contextSubjects => "CREATE TABLE `{$contextSubjects}` ("
                . "library_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "work_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "subject_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "PRIMARY KEY (library_id, work_id, subject_id), "
                . "KEY context_subjects_by_subject (library_id, subject_id), "
                . "FOREIGN KEY (library_id, work_id) REFERENCES `{$contexts}` "
                . "(library_id, work_id) ON UPDATE RESTRICT ON DELETE RESTRICT, "
                . "FOREIGN KEY (library_id, subject_id) "
                . "REFERENCES `{$subjects}` (library_id, subject_id) "
                . "ON UPDATE RESTRICT ON DELETE RESTRICT"
                . ") ENGINE=InnoDB {$charsetCollate}",
            $activityEvents => "CREATE TABLE `{$activityEvents}` ("
                . "event_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "library_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "occurred_at DATETIME(6) NOT NULL, "
                . "actor_user_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NULL, "
                . "actor_display_name LONGTEXT CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NULL, "
                . "event_source VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "event_key VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "primary_entity_type VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "primary_entity_id VARCHAR(191) CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "related_entities_json LONGTEXT CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "changes_json LONGTEXT CHARACTER SET utf8mb4 "
                . "COLLATE utf8mb4_bin NOT NULL, "
                . "PRIMARY KEY (event_id), "
                . "KEY activity_events_by_library_time "
                . "(library_id, occurred_at, event_id), "
                . "FOREIGN KEY (library_id) REFERENCES `{$libraries}` "
                . "(library_id) ON UPDATE RESTRICT ON DELETE RESTRICT, "
                . "CONSTRAINT activity_actor_snapshot_valid CHECK "
                . "(actor_user_id IS NULL OR actor_display_name IS NOT NULL), "
                . "CONSTRAINT activity_actor_name_non_empty CHECK "
                . "(actor_display_name IS NULL OR "
                . "CHAR_LENGTH(TRIM(actor_display_name)) > 0), "
                . "CONSTRAINT activity_related_entities_json "
                . "CHECK (JSON_VALID(related_entities_json)), "
                . "CONSTRAINT activity_changes_json "
                . "CHECK (JSON_VALID(changes_json))"
                . ") ENGINE=InnoDB {$charsetCollate}",
        ];
    }

    private function assertPermissionPayloadsValid(): void
    {
        foreach ($this->membershipRows() as $row) {
            $this->decodePermissions((string) $row->additional_permissions);
        }
    }

    private function backfillManagerItemAddPermission(): void
    {
        foreach ($this->membershipRows() as $row) {
            if ((string) $row->management_role !== "manager") {
                continue;
            }

            $stored = (string) $row->additional_permissions;
            $permissions = $this->decodePermissions($stored);

            if ($permissions->contains(AdditionalPermissions::CATALOG_ITEM_ADD)) {
                continue;
            }

            try {
                $encoded = json_encode(
                    $permissions
                        ->with(AdditionalPermissions::CATALOG_ITEM_ADD)
                        ->values(),
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    "Could not serialize Manager permission backfill.",
                    0,
                    $exception
                );
            }

            $result = $this->database->update(
                $this->tableNames->memberships(),
                ["additional_permissions" => $encoded],
                [
                    "library_id" => (string) $row->library_id,
                    "user_id" => (string) $row->user_id,
                    "management_role" => "manager",
                    "additional_permissions" => $stored,
                ],
                ["%s"],
                ["%s", "%s", "%s", "%s"]
            );

            if ($result !== 1) {
                throw new RuntimeException(
                    "Could not backfill Manager item-add permission."
                );
            }
        }
    }

    /** @return list<object> */
    private function membershipRows(): array
    {
        $table = $this->tableNames->memberships();

        return $this->database->get_results(
            "SELECT library_id, user_id, management_role, "
            . "additional_permissions FROM `{$table}` "
            . "ORDER BY library_id, user_id"
        );
    }

    private function decodePermissions(string $payload): AdditionalPermissions
    {
        try {
            $values = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);

            if (!is_array($values) || !array_is_list($values)) {
                throw new RuntimeException(
                    "Stored additional permissions are not a list."
                );
            }

            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw new RuntimeException(
                        "Stored additional permission is not a string."
                    );
                }
            }

            return AdditionalPermissions::fromValues(...$values);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Stored Library membership permissions are invalid.",
                0,
                $exception
            );
        }
    }

    private function tableExists(string $tableName): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $tableName
        )) === 1;
    }

    private function execute(string $sql): void
    {
        if ($this->database->query($sql) === false) {
            throw new RuntimeException(
                "Could not apply Biblio Core schema 1001: "
                . $this->database->last_error
            );
        }
    }
}
