<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1024Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $health;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
        $this->health = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1023; }
    public function targetVersion(): int { return 1024; }

    public function assertPrecondition(): void
    {
        $source = $this->health->inspectForVersion(1023);
        if (!$source->isHealthy()) {
            foreach ($source->errors() as $error) {
                if (
                    !str_contains($error, $this->tables->authors())
                    && !str_contains(
                        $error,
                        $this->tables->bibliographicProviderIdentities()
                    )
                ) {
                    throw new CoreSchemaMigrationException(
                        "Schema 1024 retry found unrelated schema drift: {$error}"
                    );
                }
            }
        }

        $this->assertKnownAlteredTableState(
            $this->tables->authors(),
            ["identity_status", "display_name_status", "author_version"],
            ["author_id", "display_name"],
            [
                "author_id",
                "display_name",
                "identity_status",
                "display_name_status",
                "author_version",
            ],
            $this->health->inspectSchema1023Authors(),
            $this->health->inspectSchema1024Authors()
        );
        $this->assertKnownAlteredTableState(
            $this->tables->bibliographicProviderIdentities(),
            ["author_id"],
            [
                "provider_key",
                "source_entity_type",
                "provider_record_id",
                "target_type",
                "work_id",
                "edition_id",
            ],
            [
                "provider_key",
                "source_entity_type",
                "provider_record_id",
                "target_type",
                "work_id",
                "edition_id",
                "author_id",
            ],
            $this->health->inspectSchema1023ProviderIdentities(),
            $this->health->inspectSchema1024ProviderIdentities()
        );

        if (!$this->columnExists(
            $this->tables->bibliographicProviderIdentities(),
            "author_id"
        )) {
            $this->assertExistingProviderMatrixCanMigrate();
        }

        $partial = $this->health->inspectExistingSchema1024Additions();
        if (!$partial->isHealthy()) {
            throw new CoreSchemaMigrationException(
                "Schema 1024 retry found an unknown Author identity state: "
                    . $partial->summary()
            );
        }
        $this->assertKnownNewTableColumns(
            $this->tables->authorContributorCredits(),
            [
                "credit_id", "credit_key", "work_id", "contributor_role",
                "contributor_position", "observed_display_name",
                "normalized_name_hash", "author_id", "materialization_status",
                "review_reason", "created_at", "updated_at", "credit_version",
            ]
        );
        $this->assertKnownNewTableColumns(
            $this->tables->authorCreditEvidence(),
            [
                "credit_id", "evidence_id", "source_kind", "source_identity",
                "provider_key", "source_entity_type", "source_record_id",
                "strong_provider_author_id", "observed_display_name",
                "contributor_role", "source_position", "first_observed_at",
                "last_observed_at", "observation_count",
            ]
        );
    }

    public function migrate(): void
    {
        $this->migrateAuthors();
        $this->migrateProviderIdentities();
        $this->createCredits();
        $this->createEvidence();
    }

    public function assertPostcondition(): void
    {
        $health = $this->health->inspectForVersion(1024);
        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    private function migrateAuthors(): void
    {
        $table = $this->tables->authors();
        if ($this->columnExists($table, "identity_status")) {
            return;
        }
        $this->execute(
            "ALTER TABLE `{$table}` "
                . "ADD identity_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'provisional' AFTER display_name,"
                . "ADD display_name_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'observed' AFTER identity_status,"
                . "ADD author_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER display_name_status,"
                . "ADD CONSTRAINT authors_identity_status_valid CHECK (identity_status IN ('provisional','resolved')),"
                . "ADD CONSTRAINT authors_display_name_status_valid CHECK (display_name_status IN ('observed','librarian_confirmed')),"
                . "ADD CONSTRAINT authors_version_positive CHECK (author_version >= 1)",
            "Author identity columns"
        );
    }

    private function migrateProviderIdentities(): void
    {
        $table = $this->tables->bibliographicProviderIdentities();
        if ($this->columnExists($table, "author_id")) {
            return;
        }
        $authors = $this->tables->authors();
        $this->execute(
            "ALTER TABLE `{$table}` "
                . "DROP CONSTRAINT bibliographic_provider_identity_source_valid,"
                . "DROP CONSTRAINT bibliographic_provider_identity_target_valid,"
                . "ADD author_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL AFTER edition_id,"
                . "ADD KEY bibliographic_provider_identity_by_author (author_id,provider_key,provider_record_id),"
                . "ADD CONSTRAINT bibliographic_provider_identity_author_fk FOREIGN KEY (author_id) REFERENCES `{$authors}` (author_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                . "ADD CONSTRAINT bibliographic_provider_identity_source_valid CHECK (source_entity_type IN ('author','work','edition')),"
                . "ADD CONSTRAINT bibliographic_provider_identity_target_valid CHECK ((target_type='work' AND work_id IS NOT NULL AND edition_id IS NULL AND author_id IS NULL) OR (target_type='edition' AND work_id IS NULL AND edition_id IS NOT NULL AND author_id IS NULL) OR (target_type='author' AND work_id IS NULL AND edition_id IS NULL AND author_id IS NOT NULL)),"
                . "ADD CONSTRAINT bibliographic_provider_identity_matrix_valid CHECK ((source_entity_type='author' AND target_type='author') OR (source_entity_type='work' AND target_type='work') OR (source_entity_type='edition' AND target_type IN ('work','edition')))",
            "bibliographic provider Author identities"
        );
    }

    private function createCredits(): void
    {
        $table = $this->tables->authorContributorCredits();
        if ($this->tableExists($table)) {
            return;
        }
        $works = $this->tables->works();
        $authors = $this->tables->authors();
        $collation = $this->database->get_charset_collate();
        $this->execute(
            "CREATE TABLE `{$table}` ("
                . "credit_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "credit_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                . "work_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "contributor_role VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                . "contributor_position BIGINT UNSIGNED NOT NULL,"
                . "observed_display_name VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "normalized_name_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                . "author_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
                . "materialization_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                . "review_reason VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,"
                . "created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,"
                . "credit_version BIGINT UNSIGNED NOT NULL,"
                . "PRIMARY KEY (credit_id),"
                . "UNIQUE KEY author_credit_key_unique (credit_key),"
                . "KEY author_credit_by_work_position (work_id,contributor_position,credit_id),"
                . "KEY author_credit_by_author (author_id,credit_id),"
                . "CONSTRAINT author_credit_work_fk FOREIGN KEY (work_id) REFERENCES `{$works}` (work_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                . "CONSTRAINT author_credit_author_fk FOREIGN KEY (author_id) REFERENCES `{$authors}` (author_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                . "CONSTRAINT author_credit_id_valid CHECK (CHAR_LENGTH(TRIM(credit_id)) > 0),"
                . "CONSTRAINT author_credit_key_valid CHECK (credit_key REGEXP '^[0-9a-f]{64}$'),"
                . "CONSTRAINT author_credit_role_valid CHECK (contributor_role IN ('author','co_author')),"
                . "CONSTRAINT author_credit_position_positive CHECK (contributor_position >= 1),"
                . "CONSTRAINT author_credit_name_valid CHECK (CHAR_LENGTH(TRIM(observed_display_name)) > 0),"
                . "CONSTRAINT author_credit_name_hash_valid CHECK (normalized_name_hash REGEXP '^[0-9a-f]{64}$'),"
                . "CONSTRAINT author_credit_status_valid CHECK (materialization_status IN ('linked','unresolved')),"
                . "CONSTRAINT author_credit_review_valid CHECK (review_reason IS NULL OR review_reason IN ('identity_conflict','ambiguous_match','structural_ambiguity','possible_duplicate')),"
                . "CONSTRAINT author_credit_link_valid CHECK ((materialization_status='linked' AND author_id IS NOT NULL) OR (materialization_status='unresolved' AND author_id IS NULL AND review_reason IS NOT NULL)),"
                . "CONSTRAINT author_credit_time_valid CHECK (updated_at >= created_at),"
                . "CONSTRAINT author_credit_version_positive CHECK (credit_version >= 1)"
                . ") ENGINE=InnoDB {$collation}",
            "Author contributor credits"
        );
    }

    private function createEvidence(): void
    {
        $table = $this->tables->authorCreditEvidence();
        if ($this->tableExists($table)) {
            return;
        }
        $credits = $this->tables->authorContributorCredits();
        $collation = $this->database->get_charset_collate();
        $this->execute(
            "CREATE TABLE `{$table}` ("
                . "credit_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "evidence_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                . "source_kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                . "source_identity CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                . "provider_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,"
                . "source_entity_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,"
                . "source_record_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
                . "strong_provider_author_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
                . "observed_display_name VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "contributor_role VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                . "source_position BIGINT UNSIGNED NOT NULL,"
                . "first_observed_at DATETIME(6) NOT NULL,last_observed_at DATETIME(6) NOT NULL,"
                . "observation_count BIGINT UNSIGNED NOT NULL,"
                . "PRIMARY KEY (credit_id,evidence_id),"
                . "KEY author_credit_evidence_by_provider_author (provider_key,strong_provider_author_id,credit_id),"
                . "CONSTRAINT author_credit_evidence_credit_fk FOREIGN KEY (credit_id) REFERENCES `{$credits}` (credit_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                . "CONSTRAINT author_credit_evidence_id_valid CHECK (evidence_id REGEXP '^[0-9a-f]{64}$'),"
                . "CONSTRAINT author_credit_evidence_source_id_valid CHECK (source_identity REGEXP '^[0-9a-f]{64}$'),"
                . "CONSTRAINT author_credit_evidence_source_valid CHECK (source_kind IN ('provider','user_observation','migration')),"
                . "CONSTRAINT author_credit_evidence_shape_valid CHECK ((source_kind='provider' AND provider_key IS NOT NULL AND provider_key REGEXP '^[a-z][a-z0-9_]{0,63}$' AND source_entity_type IN ('work','edition') AND source_record_id IS NOT NULL AND CHAR_LENGTH(TRIM(source_record_id)) > 0 AND (strong_provider_author_id IS NULL OR CHAR_LENGTH(TRIM(strong_provider_author_id)) > 0)) OR (source_kind IN ('user_observation','migration') AND provider_key IS NULL AND source_entity_type IS NULL AND source_record_id IS NOT NULL AND CHAR_LENGTH(TRIM(source_record_id)) > 0 AND strong_provider_author_id IS NULL)),"
                . "CONSTRAINT author_credit_evidence_name_valid CHECK (CHAR_LENGTH(TRIM(observed_display_name)) > 0),"
                . "CONSTRAINT author_credit_evidence_role_valid CHECK (contributor_role IN ('author','co_author')),"
                . "CONSTRAINT author_credit_evidence_position_positive CHECK (source_position >= 1),"
                . "CONSTRAINT author_credit_evidence_time_valid CHECK (last_observed_at >= first_observed_at),"
                . "CONSTRAINT author_credit_evidence_count_positive CHECK (observation_count >= 1)"
                . ") ENGINE=InnoDB {$collation}",
            "Author credit evidence"
        );
    }

    /**
     * @param list<string> $newColumns
     * @param list<string> $oldShape
     * @param list<string> $newShape
     */
    private function assertKnownAlteredTableState(
        string $table,
        array $newColumns,
        array $oldShape,
        array $newShape,
        CoreSchemaHealth $oldHealth,
        CoreSchemaHealth $newHealth
    ): void {
        $present = array_filter(
            $newColumns,
            fn (string $column): bool => $this->columnExists($table, $column)
        );
        if ($present !== [] && count($present) !== count($newColumns)) {
            throw new CoreSchemaMigrationException(
                "Schema 1024 retry found a partial altered table {$table}."
            );
        }
        $health = $present === [] ? $oldHealth : $newHealth;
        if (!$health->isHealthy()) {
            throw new CoreSchemaMigrationException(
                "Schema 1024 retry found an unknown altered table state: "
                    . $health->summary()
            );
        }
        $expectedShape = $present === [] ? $oldShape : $newShape;
        if ($this->columns($table) !== $expectedShape) {
            throw new CoreSchemaMigrationException(
                "Schema 1024 retry found unknown columns in {$table}."
            );
        }
    }

    /** @param list<string> $expectedColumns */
    private function assertKnownNewTableColumns(
        string $table,
        array $expectedColumns
    ): void {
        if ($this->tableExists($table) && $this->columns($table) !== $expectedColumns) {
            throw new CoreSchemaMigrationException(
                "Schema 1024 retry found unknown columns in {$table}."
            );
        }
    }

    private function assertExistingProviderMatrixCanMigrate(): void
    {
        $table = $this->tables->bibliographicProviderIdentities();
        $invalid = (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE NOT ("
                . "(source_entity_type='work' AND target_type='work') OR "
                . "(source_entity_type='edition' AND target_type IN ('work','edition')))"
        );
        if ($invalid > 0) {
            throw new CoreSchemaMigrationException(
                "Schema 1024 cannot close the provider target matrix: "
                    . "{$invalid} existing claim(s) are outside the canonical matrix."
            );
        }
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES "
                . "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME,
            $table
        )) === 1;
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s",
            DB_NAME,
            $table,
            $column
        )) === 1;
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return array_map("strval", $this->database->get_col(
            $this->database->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
                    . "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s "
                    . "ORDER BY ORDINAL_POSITION",
                DB_NAME,
                $table
            )
        ));
    }

    private function execute(string $sql, string $subject): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not migrate schema 1024 {$subject}: "
                    . $this->database->last_error
            );
        }
    }
}
