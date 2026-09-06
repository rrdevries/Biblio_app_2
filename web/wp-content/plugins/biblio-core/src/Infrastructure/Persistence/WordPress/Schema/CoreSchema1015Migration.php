<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1015Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $health;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
        $this->health = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1014; }
    public function targetVersion(): int { return 1015; }

    public function assertPrecondition(): void
    {
        $source = $this->health->inspectForVersion(1014);
        if (!$source->isHealthy()) {
            throw new CoreSchemaHealthException($source);
        }

        $additions = $this->health->inspectExistingSchema1015Additions();
        if (!$additions->isHealthy()) {
            throw new CoreSchemaMigrationException(
                "Schema 1015 retry found an unknown Metadata field-review state: "
                    . $additions->summary()
            );
        }
    }

    public function migrate(): void
    {
        $collation = $this->database->get_charset_collate();
        $states = $this->tables->metadataFieldStates();
        if (!$this->tableExists($states)) {
            $this->execute(
                "CREATE TABLE `{$states}` ("
                    . "metadata_record_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "field_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "canonical_value_json LONGTEXT NULL,"
                    . "canonical_value_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,"
                    . "confirmation_state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "confirmed_by_user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
                    . "field_version BIGINT UNSIGNED NOT NULL,"
                    . "updated_at DATETIME(6) NOT NULL,"
                    . "PRIMARY KEY (metadata_record_id,field_key),"
                    . "KEY metadata_field_states_by_confirmation (confirmation_state,updated_at,metadata_record_id,field_key),"
                    . "CONSTRAINT metadata_record_id_non_empty CHECK (CHAR_LENGTH(TRIM(metadata_record_id)) > 0),"
                    . "CONSTRAINT metadata_field_key_supported CHECK (field_key IN ('title','subtitle','contributors','languages','publishers','publication_date','page_count','format')),"
                    . "CONSTRAINT metadata_field_canonical_json_valid CHECK (canonical_value_json IS NULL OR JSON_VALID(canonical_value_json)),"
                    . "CONSTRAINT metadata_field_canonical_hash_valid CHECK (canonical_value_hash IS NULL OR canonical_value_hash REGEXP '^[0-9a-f]{64}$'),"
                    . "CONSTRAINT metadata_field_canonical_hash_matches CHECK (canonical_value_hash IS NULL OR BINARY canonical_value_hash = BINARY SHA2(canonical_value_json,256)),"
                    . "CONSTRAINT metadata_field_confirmation_supported CHECK (confirmation_state IN ('unknown','unconfirmed','user_confirmed','intentionally_blank')),"
                    . "CONSTRAINT metadata_field_confirmation_consistent CHECK ((confirmation_state='unknown' AND canonical_value_json IS NULL AND canonical_value_hash IS NULL AND confirmed_by_user_id IS NULL) OR (confirmation_state='unconfirmed' AND canonical_value_json IS NOT NULL AND canonical_value_hash IS NOT NULL AND confirmed_by_user_id IS NULL) OR (confirmation_state='user_confirmed' AND canonical_value_json IS NOT NULL AND canonical_value_hash IS NOT NULL AND confirmed_by_user_id IS NOT NULL) OR (confirmation_state='intentionally_blank' AND canonical_value_json IS NULL AND canonical_value_hash IS NULL AND confirmed_by_user_id IS NOT NULL)),"
                    . "CONSTRAINT metadata_field_actor_non_empty CHECK (confirmed_by_user_id IS NULL OR CHAR_LENGTH(TRIM(confirmed_by_user_id)) > 0),"
                    . "CONSTRAINT metadata_field_version_positive CHECK (field_version >= 1)"
                    . ") ENGINE=InnoDB {$collation}",
                "Metadata field states"
            );
        }

        $values = $this->tables->metadataFieldValues();
        if (!$this->tableExists($values)) {
            $this->execute(
                "CREATE TABLE `{$values}` ("
                    . "metadata_record_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "field_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "value_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "value_json LONGTEXT NOT NULL,"
                    . "review_state VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "first_seen_at DATETIME(6) NOT NULL,"
                    . "last_seen_at DATETIME(6) NOT NULL,"
                    . "decided_by_user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
                    . "decided_at DATETIME(6) NULL,"
                    . "PRIMARY KEY (metadata_record_id,field_key,value_hash),"
                    . "KEY metadata_field_values_active (metadata_record_id,field_key,review_state,last_seen_at),"
                    . "CONSTRAINT metadata_field_value_state_fk FOREIGN KEY (metadata_record_id,field_key) REFERENCES `{$states}` (metadata_record_id,field_key) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT metadata_field_value_hash_valid CHECK (value_hash REGEXP '^[0-9a-f]{64}$'),"
                    . "CONSTRAINT metadata_field_value_json_valid CHECK (JSON_VALID(value_json)),"
                    . "CONSTRAINT metadata_field_value_hash_matches CHECK (BINARY value_hash = BINARY SHA2(value_json,256)),"
                    . "CONSTRAINT metadata_field_review_state_supported CHECK (review_state IN ('active','supporting','rejected','superseded','confirmed','blocked_by_intentional_blank')),"
                    . "CONSTRAINT metadata_field_seen_order_valid CHECK (last_seen_at >= first_seen_at),"
                    . "CONSTRAINT metadata_field_decision_consistent CHECK ((decided_by_user_id IS NULL AND decided_at IS NULL) OR (decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL))"
                    . ",CONSTRAINT metadata_field_decider_non_empty CHECK (decided_by_user_id IS NULL OR CHAR_LENGTH(TRIM(decided_by_user_id)) > 0)"
                    . ") ENGINE=InnoDB {$collation}",
                "Metadata field values"
            );
        }

        $evidence = $this->tables->metadataFieldEvidence();
        if (!$this->tableExists($evidence)) {
            $this->execute(
                "CREATE TABLE `{$evidence}` ("
                    . "evidence_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "metadata_record_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "field_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "value_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "provider_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "provider_record_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "first_retrieved_at DATETIME(6) NOT NULL,"
                    . "last_retrieved_at DATETIME(6) NOT NULL,"
                    . "observation_count BIGINT UNSIGNED NOT NULL,"
                    . "match_method VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "queried_identifier_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "queried_identifier VARCHAR(13) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "PRIMARY KEY (evidence_id),"
                    . "KEY metadata_field_evidence_by_value (metadata_record_id,field_key,value_hash,first_retrieved_at,evidence_id),"
                    . "CONSTRAINT metadata_field_evidence_value_fk FOREIGN KEY (metadata_record_id,field_key,value_hash) REFERENCES `{$values}` (metadata_record_id,field_key,value_hash) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT metadata_field_evidence_id_valid CHECK (evidence_id REGEXP '^[0-9a-f]{64}$'),"
                    . "CONSTRAINT metadata_field_evidence_provider_non_empty CHECK (CHAR_LENGTH(TRIM(provider_key)) > 0),"
                    . "CONSTRAINT metadata_field_evidence_record_non_empty CHECK (CHAR_LENGTH(TRIM(provider_record_id)) > 0),"
                    . "CONSTRAINT metadata_field_evidence_time_valid CHECK (last_retrieved_at >= first_retrieved_at),"
                    . "CONSTRAINT metadata_field_evidence_count_positive CHECK (observation_count >= 1),"
                    . "CONSTRAINT metadata_field_evidence_match_supported CHECK (match_method = 'exact_isbn'),"
                    . "CONSTRAINT metadata_field_evidence_query_valid CHECK ((queried_identifier_type='isbn_10' AND queried_identifier REGEXP '^[0-9]{9}[0-9X]$') OR (queried_identifier_type='isbn_13' AND queried_identifier REGEXP '^97[89][0-9]{10}$'))"
                    . ") ENGINE=InnoDB {$collation}",
                "Metadata field evidence"
            );
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->health->inspectForVersion(1015);
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
                "Could not create schema 1015 component {$component}: "
                    . $this->database->last_error
            );
        }
    }
}
