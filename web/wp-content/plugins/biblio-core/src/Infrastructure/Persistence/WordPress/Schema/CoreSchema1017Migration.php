<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1017Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $health;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
        $this->health = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1016; }
    public function targetVersion(): int { return 1017; }

    public function assertPrecondition(): void
    {
        $source = $this->health->inspectForVersion(1016);
        if (!$source->isHealthy()) {
            throw new CoreSchemaHealthException($source);
        }

        $additions = $this->health->inspectExistingSchema1017Additions();
        if (!$additions->isHealthy()) {
            throw new CoreSchemaMigrationException(
                "Schema 1017 retry found an unknown Add Book evidence state: "
                    . $additions->summary()
            );
        }
    }

    public function migrate(): void
    {
        $collation = $this->database->get_charset_collate();
        $libraries = $this->tables->libraries();
        $editions = $this->tables->editions();
        $items = $this->tables->items();
        $snapshots = $this->tables->metadataLookupSnapshots();
        $candidates = $this->tables->metadataLookupCandidates();
        $observations = $this->tables->metadataUserObservations();

        if (!$this->tableExists($snapshots)) {
            $this->execute(
                "CREATE TABLE `{$snapshots}` ("
                    . "lookup_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "actor_user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "library_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "canonical_isbn_13 CHAR(13) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "created_at DATETIME(6) NOT NULL,"
                    . "expires_at DATETIME(6) NOT NULL,"
                    . "PRIMARY KEY (lookup_id),"
                    . "KEY metadata_lookup_by_scope_expiry (actor_user_id,library_id,expires_at,lookup_id),"
                    . "CONSTRAINT metadata_lookup_library_fk FOREIGN KEY (library_id) REFERENCES `{$libraries}` (library_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT metadata_lookup_id_valid CHECK (lookup_id REGEXP '^lookup-[0-9a-f]{32}$'),"
                    . "CONSTRAINT metadata_lookup_actor_non_empty CHECK (CHAR_LENGTH(TRIM(actor_user_id)) > 0),"
                    . "CONSTRAINT metadata_lookup_isbn_valid CHECK (canonical_isbn_13 REGEXP '^97[89][0-9]{10}$'),"
                    . "CONSTRAINT metadata_lookup_time_valid CHECK (expires_at > created_at)"
                    . ") ENGINE=InnoDB {$collation}",
                "metadata lookup snapshots"
            );
        }

        if (!$this->tableExists($candidates)) {
            $this->execute(
                "CREATE TABLE `{$candidates}` ("
                    . "lookup_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "candidate_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "candidate_json LONGTEXT NOT NULL,"
                    . "candidate_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "PRIMARY KEY (lookup_id,candidate_id),"
                    . "CONSTRAINT metadata_lookup_candidate_snapshot_fk FOREIGN KEY (lookup_id) REFERENCES `{$snapshots}` (lookup_id) ON UPDATE RESTRICT ON DELETE CASCADE,"
                    . "CONSTRAINT metadata_lookup_candidate_id_valid CHECK (candidate_id REGEXP '^[0-9a-f]{64}$'),"
                    . "CONSTRAINT metadata_lookup_candidate_json_valid CHECK (JSON_VALID(candidate_json) AND CHAR_LENGTH(candidate_json) <= 32768),"
                    . "CONSTRAINT metadata_lookup_candidate_hash_valid CHECK (candidate_hash REGEXP '^[0-9a-f]{64}$'),"
                    . "CONSTRAINT metadata_lookup_candidate_hash_matches CHECK (BINARY candidate_hash = BINARY SHA2(candidate_json,256))"
                    . ") ENGINE=InnoDB {$collation}",
                "metadata lookup candidates"
            );
        }

        if (!$this->tableExists($observations)) {
            $this->execute(
                "CREATE TABLE `{$observations}` ("
                    . "observation_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "metadata_record_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "field_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "value_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "value_json LONGTEXT NOT NULL,"
                    . "edition_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "library_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "item_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "actor_user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "observed_at DATETIME(6) NOT NULL,"
                    . "source_context VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "correction_proposal TINYINT UNSIGNED NOT NULL,"
                    . "PRIMARY KEY (observation_id),"
                    . "KEY metadata_user_observations_by_edition (edition_id,field_key,observed_at,observation_id),"
                    . "KEY metadata_user_observations_by_scope (library_id,item_id,observed_at,observation_id),"
                    . "CONSTRAINT metadata_user_observation_edition_fk FOREIGN KEY (edition_id) REFERENCES `{$editions}` (edition_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT metadata_user_observation_item_fk FOREIGN KEY (library_id,item_id) REFERENCES `{$items}` (library_id,item_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT metadata_user_observation_id_valid CHECK (observation_id REGEXP '^[0-9a-f]{64}$'),"
                    . "CONSTRAINT metadata_user_observation_field_supported CHECK (field_key IN ('isbn','title','subtitle','contributors','languages','publishers','publication_date','edition_statement','format','page_count')),"
                    . "CONSTRAINT metadata_user_observation_json_valid CHECK (JSON_VALID(value_json)),"
                    . "CONSTRAINT metadata_user_observation_hash_valid CHECK (value_hash REGEXP '^[0-9a-f]{64}$'),"
                    . "CONSTRAINT metadata_user_observation_hash_matches CHECK (BINARY value_hash = BINARY SHA2(value_json,256)),"
                    . "CONSTRAINT metadata_user_observation_actor_non_empty CHECK (CHAR_LENGTH(TRIM(actor_user_id)) > 0),"
                    . "CONSTRAINT metadata_user_observation_source_supported CHECK (source_context = 'physical_copy_add_book'),"
                    . "CONSTRAINT metadata_user_observation_correction_valid CHECK (correction_proposal IN (0,1))"
                    . ") ENGINE=InnoDB {$collation}",
                "user-observed metadata evidence"
            );
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->health->inspectForVersion(1017);
        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
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

    private function execute(string $sql, string $component): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not create schema 1017 component {$component}: "
                    . $this->database->last_error
            );
        }
    }
}
