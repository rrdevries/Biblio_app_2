<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1018Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $health;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
        $this->health = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1017; }
    public function targetVersion(): int { return 1018; }

    public function assertPrecondition(): void
    {
        $source = $this->health->inspectForVersion(1017);
        if (!$source->isHealthy()) {
            throw new CoreSchemaHealthException($source);
        }

        $additions = $this->health->inspectExistingSchema1018Additions();
        if (!$additions->isHealthy()) {
            throw new CoreSchemaMigrationException(
                "Schema 1018 retry found an unknown migration foundation state: "
                . $additions->summary()
            );
        }
    }

    public function migrate(): void
    {
        $collation = $this->database->get_charset_collate();
        $libraries = $this->tables->libraries();
        $runs = $this->tables->migrationRuns();
        $locks = $this->tables->migrationRunLocks();
        $observations = $this->tables->migrationSourceObservations();
        $mappings = $this->tables->migrationTargetMappings();
        $quarantine = $this->tables->migrationQuarantine();
        $preservations = $this->tables->migrationPreservations();

        if (!$this->tableExists($runs)) {
            $this->execute(
                "CREATE TABLE `{$runs}` ("
                    . "run_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "source_family VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "source_snapshot VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "source_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "source_version VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
                    . "migrator_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "target_user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "target_library_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "run_mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "run_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "summary_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "created_at DATETIME(6) NOT NULL,"
                    . "started_at DATETIME(6) NULL,"
                    . "finished_at DATETIME(6) NULL,"
                    . "PRIMARY KEY (run_id),"
                    . "UNIQUE KEY migration_run_identity_unique (source_family,source_snapshot,source_fingerprint,migrator_version,target_user_id,target_library_id,run_mode),"
                    . "KEY migration_runs_by_target_status (target_user_id,target_library_id,run_status,created_at,run_id),"
                    . "CONSTRAINT migration_run_library_fk FOREIGN KEY (target_library_id) REFERENCES `{$libraries}` (library_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT migration_run_fingerprint_valid CHECK (source_fingerprint REGEXP '^[0-9a-f]{64}$'),"
                    . "CONSTRAINT migration_run_mode_valid CHECK (run_mode IN ('dry_run','apply')),"
                    . "CONSTRAINT migration_run_status_valid CHECK (run_status IN ('planned','running','interrupted','failed','completed')),"
                    . "CONSTRAINT migration_run_summary_valid CHECK (summary_status IN ('pending','reconciled','failed')),"
                    . "CONSTRAINT migration_run_identity_non_empty CHECK (CHAR_LENGTH(TRIM(source_family)) > 0 AND CHAR_LENGTH(TRIM(source_snapshot)) > 0 AND CHAR_LENGTH(TRIM(migrator_version)) > 0 AND CHAR_LENGTH(TRIM(target_user_id)) > 0),"
                    . "CONSTRAINT migration_run_times_valid CHECK ((started_at IS NULL OR started_at >= created_at) AND (finished_at IS NULL OR started_at IS NOT NULL AND finished_at >= started_at)),"
                    . "CONSTRAINT migration_run_completion_valid CHECK (run_status <> 'completed' OR finished_at IS NOT NULL AND summary_status = 'reconciled'),"
                    . "CONSTRAINT migration_run_failure_valid CHECK (summary_status <> 'failed' OR run_status = 'failed')"
                    . ") ENGINE=InnoDB {$collation}",
                "migration runs"
            );
        }

        if (!$this->tableExists($locks)) {
            $this->execute(
                "CREATE TABLE `{$locks}` ("
                    . "target_user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "target_library_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "run_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "acquired_at DATETIME(6) NOT NULL,"
                    . "PRIMARY KEY (target_user_id,target_library_id),"
                    . "UNIQUE KEY migration_run_lock_by_run (run_id),"
                    . "CONSTRAINT migration_run_lock_run_fk FOREIGN KEY (run_id) REFERENCES `{$runs}` (run_id) ON UPDATE RESTRICT ON DELETE CASCADE,"
                    . "CONSTRAINT migration_run_lock_library_fk FOREIGN KEY (target_library_id) REFERENCES `{$libraries}` (library_id) ON UPDATE RESTRICT ON DELETE RESTRICT"
                    . ") ENGINE=InnoDB {$collation}",
                "migration run locks"
            );
        }

        if (!$this->tableExists($observations)) {
            $this->execute(
                "CREATE TABLE `{$observations}` ("
                    . "observation_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "run_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "source_family VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "source_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "source_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "source_snapshot VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "payload_json LONGTEXT NULL,"
                    . "payload_reference VARCHAR(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
                    . "processing_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "disposition VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,"
                    . "reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,"
                    . "retryable TINYINT UNSIGNED NOT NULL,"
                    . "created_at DATETIME(6) NOT NULL,"
                    . "updated_at DATETIME(6) NOT NULL,"
                    . "PRIMARY KEY (observation_id),"
                    . "UNIQUE KEY migration_observation_run_source_unique (run_id,source_family,source_type,source_id),"
                    . "KEY migration_observations_by_logical_source (source_family,source_type,source_id,source_snapshot,payload_hash),"
                    . "KEY migration_observations_by_run_disposition (run_id,disposition,processing_status,observation_id),"
                    . "CONSTRAINT migration_observation_run_fk FOREIGN KEY (run_id) REFERENCES `{$runs}` (run_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT migration_observation_id_valid CHECK (observation_id REGEXP '^[0-9a-f]{64}$'),"
                    . "CONSTRAINT migration_observation_payload_hash_valid CHECK (payload_hash REGEXP '^[0-9a-f]{64}$'),"
                    . "CONSTRAINT migration_observation_payload_valid CHECK (payload_json IS NULL OR JSON_VALID(payload_json) AND CHAR_LENGTH(payload_json) <= 65535),"
                    . "CONSTRAINT migration_observation_source_valid CHECK (CHAR_LENGTH(TRIM(source_family)) > 0 AND CHAR_LENGTH(TRIM(source_type)) > 0 AND CHAR_LENGTH(TRIM(source_id)) > 0 AND CHAR_LENGTH(TRIM(source_snapshot)) > 0),"
                    . "CONSTRAINT migration_observation_processing_valid CHECK (processing_status IN ('observed','processing','committed','retryable_failure','terminal')),"
                    . "CONSTRAINT migration_observation_disposition_valid CHECK (disposition IS NULL OR disposition IN ('mapped','transformed','preserved_deferred','quarantined','intentionally_dropped','failed')),"
                    . "CONSTRAINT migration_observation_retryable_valid CHECK (retryable IN (0,1)),"
                    . "CONSTRAINT migration_observation_outcome_valid CHECK ((processing_status IN ('observed','processing') AND disposition IS NULL) OR (processing_status IN ('committed','retryable_failure','terminal') AND disposition IS NOT NULL)),"
                    . "CONSTRAINT migration_observation_reason_valid CHECK (disposition NOT IN ('intentionally_dropped','failed') OR reason_code IS NOT NULL AND CHAR_LENGTH(TRIM(reason_code)) > 0),"
                    . "CONSTRAINT migration_observation_times_valid CHECK (updated_at >= created_at)"
                    . ") ENGINE=InnoDB {$collation}",
                "migration source observations"
            );
        }

        if (!$this->tableExists($mappings)) {
            $this->execute(
                "CREATE TABLE `{$mappings}` ("
                    . "mapping_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "run_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "observation_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "target_entity_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "target_entity_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "mapping_disposition VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "mapping_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,"
                    . "created_at DATETIME(6) NOT NULL,"
                    . "PRIMARY KEY (mapping_id),"
                    . "UNIQUE KEY migration_mapping_edge_unique (run_id,observation_id,target_entity_type,target_entity_id),"
                    . "KEY migration_mappings_by_source (observation_id,target_entity_type,target_entity_id),"
                    . "KEY migration_mappings_by_target (target_entity_type,target_entity_id,run_id,observation_id),"
                    . "CONSTRAINT migration_mapping_run_fk FOREIGN KEY (run_id) REFERENCES `{$runs}` (run_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT migration_mapping_observation_fk FOREIGN KEY (observation_id) REFERENCES `{$observations}` (observation_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT migration_mapping_id_valid CHECK (mapping_id REGEXP '^[0-9a-f]{64}$'),"
                    . "CONSTRAINT migration_mapping_target_valid CHECK (CHAR_LENGTH(TRIM(target_entity_type)) > 0 AND CHAR_LENGTH(TRIM(target_entity_id)) > 0),"
                    . "CONSTRAINT migration_mapping_disposition_valid CHECK (mapping_disposition IN ('created','reused')),"
                    . "CONSTRAINT migration_mapping_status_valid CHECK (mapping_status IN ('committed'))"
                    . ") ENGINE=InnoDB {$collation}",
                "migration target mappings"
            );
        }

        if (!$this->tableExists($quarantine)) {
            $reasons = "'invalid_isbn_claim','canonical_isbn_identity_conflict','source_identity_conflict','missing_required_target_field','orphan_reference','reading_truth_conflict','unknown_taxonomy_mapping','unresolved_work_identity','structural_ambiguity','ambiguous_contributor','ambiguous_circulation_semantics','unsupported_target_representation'";
            $this->execute(
                "CREATE TABLE `{$quarantine}` ("
                    . "quarantine_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "run_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "observation_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "explanation VARCHAR(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "resolution_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "evidence_json LONGTEXT NULL,"
                    . "evidence_reference VARCHAR(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
                    . "created_at DATETIME(6) NOT NULL,"
                    . "resolved_at DATETIME(6) NULL,"
                    . "PRIMARY KEY (quarantine_id),"
                    . "UNIQUE KEY migration_quarantine_observation_unique (observation_id),"
                    . "KEY migration_quarantine_by_run_resolution (run_id,resolution_status,reason_code,observation_id),"
                    . "CONSTRAINT migration_quarantine_run_fk FOREIGN KEY (run_id) REFERENCES `{$runs}` (run_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT migration_quarantine_observation_fk FOREIGN KEY (observation_id) REFERENCES `{$observations}` (observation_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT migration_quarantine_id_valid CHECK (quarantine_id REGEXP '^[0-9a-f]{64}$'),"
                    . "CONSTRAINT migration_quarantine_reason_valid CHECK (reason_code IN ({$reasons})),"
                    . "CONSTRAINT migration_quarantine_explanation_valid CHECK (CHAR_LENGTH(TRIM(explanation)) > 0),"
                    . "CONSTRAINT migration_quarantine_resolution_valid CHECK (resolution_status IN ('open','resolved','dismissed')),"
                    . "CONSTRAINT migration_quarantine_evidence_valid CHECK (evidence_json IS NULL OR JSON_VALID(evidence_json) AND CHAR_LENGTH(evidence_json) <= 65535),"
                    . "CONSTRAINT migration_quarantine_resolution_time_valid CHECK ((resolution_status = 'open' AND resolved_at IS NULL) OR (resolution_status <> 'open' AND resolved_at IS NOT NULL AND resolved_at >= created_at))"
                    . ") ENGINE=InnoDB {$collation}",
                "migration quarantine"
            );
        }

        if (!$this->tableExists($preservations)) {
            $this->execute(
                "CREATE TABLE `{$preservations}` ("
                    . "preservation_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "run_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "observation_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "processing_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "evidence_json LONGTEXT NULL,"
                    . "evidence_reference VARCHAR(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
                    . "created_at DATETIME(6) NOT NULL,"
                    . "processed_at DATETIME(6) NULL,"
                    . "PRIMARY KEY (preservation_id),"
                    . "UNIQUE KEY migration_preservation_observation_unique (observation_id),"
                    . "KEY migration_preservations_by_run_status (run_id,processing_status,reason_code,observation_id),"
                    . "CONSTRAINT migration_preservation_run_fk FOREIGN KEY (run_id) REFERENCES `{$runs}` (run_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT migration_preservation_observation_fk FOREIGN KEY (observation_id) REFERENCES `{$observations}` (observation_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT migration_preservation_id_valid CHECK (preservation_id REGEXP '^[0-9a-f]{64}$'),"
                    . "CONSTRAINT migration_preservation_reason_valid CHECK (CHAR_LENGTH(TRIM(reason_code)) > 0),"
                    . "CONSTRAINT migration_preservation_status_valid CHECK (processing_status IN ('awaiting_future_processing','processed')),"
                    . "CONSTRAINT migration_preservation_evidence_valid CHECK (evidence_json IS NULL OR JSON_VALID(evidence_json) AND CHAR_LENGTH(evidence_json) <= 65535),"
                    . "CONSTRAINT migration_preservation_time_valid CHECK ((processing_status = 'awaiting_future_processing' AND processed_at IS NULL) OR (processing_status = 'processed' AND processed_at IS NOT NULL AND processed_at >= created_at))"
                    . ") ENGINE=InnoDB {$collation}",
                "migration preservation"
            );
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->health->inspectForVersion(1018);
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
                "Could not create schema 1018 component {$component}: "
                . $this->database->last_error
            );
        }
    }
}
