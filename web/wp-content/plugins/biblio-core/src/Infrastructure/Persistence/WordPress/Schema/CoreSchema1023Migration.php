<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1023Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $health;

    public function __construct(private wpdb $database, private CoreTableNames $tables)
    {
        $this->health = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1022; }
    public function targetVersion(): int { return 1023; }

    public function assertPrecondition(): void
    {
        if (!$this->evidenceAlreadyGeneralized()) {
            $source = $this->health->inspectForVersion(1022);
            if (!$source->isHealthy()) { throw new CoreSchemaHealthException($source); }
        } else {
            $evidence = $this->health->inspectSchema1023Evidence();
            if (!$evidence->isHealthy()) {
                throw new CoreSchemaMigrationException(
                    "Schema 1023 retry found an unknown generic evidence state: "
                        . $evidence->summary()
                );
            }
        }
        $partial = $this->health->inspectExistingSchema1023Additions();
        if (!$partial->isHealthy()) {
            throw new CoreSchemaMigrationException(
                "Schema 1023 retry found an unknown discovery state: "
                    . $partial->summary()
            );
        }
    }

    public function migrate(): void
    {
        $collation = $this->database->get_charset_collate();
        $works = $this->tables->works();
        $editions = $this->tables->editions();
        $snapshots = $this->tables->bibliographicDiscoverySnapshots();
        $candidates = $this->tables->bibliographicDiscoveryCandidates();
        $identities = $this->tables->bibliographicProviderIdentities();

        if (!$this->tableExists($snapshots)) {
            $this->execute(
                "CREATE TABLE `{$snapshots}` ("
                    . "discovery_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "actor_user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "query_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "normalized_query VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "canonical_isbn_13 CHAR(13) CHARACTER SET ascii COLLATE ascii_bin NULL,"
                    . "created_at DATETIME(6) NOT NULL,expires_at DATETIME(6) NOT NULL,"
                    . "PRIMARY KEY (discovery_id),"
                    . "KEY bibliographic_discovery_by_actor_expiry (actor_user_id,expires_at,discovery_id),"
                    . "CONSTRAINT bibliographic_discovery_id_valid CHECK (discovery_id REGEXP '^lookup-[0-9a-f]{32}$'),"
                    . "CONSTRAINT bibliographic_discovery_actor_valid CHECK (CHAR_LENGTH(TRIM(actor_user_id)) > 0),"
                    . "CONSTRAINT bibliographic_discovery_query_valid CHECK ((query_type='isbn' AND canonical_isbn_13 REGEXP '^97[89][0-9]{10}$' AND BINARY normalized_query=BINARY canonical_isbn_13) OR (query_type='text' AND canonical_isbn_13 IS NULL AND CHAR_LENGTH(TRIM(normalized_query)) > 0)),"
                    . "CONSTRAINT bibliographic_discovery_time_valid CHECK (expires_at > created_at)"
                    . ") ENGINE=InnoDB {$collation}",
                "bibliographic discovery snapshots"
            );
        }

        if (!$this->tableExists($candidates)) {
            $this->execute(
                "CREATE TABLE `{$candidates}` ("
                    . "discovery_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "candidate_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "candidate_json LONGTEXT NOT NULL,candidate_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "PRIMARY KEY (discovery_id,candidate_id),"
                    . "CONSTRAINT bibliographic_discovery_candidate_fk FOREIGN KEY (discovery_id) REFERENCES `{$snapshots}` (discovery_id) ON UPDATE RESTRICT ON DELETE CASCADE,"
                    . "CONSTRAINT bibliographic_discovery_candidate_id_valid CHECK (candidate_id REGEXP '^[0-9a-f]{64}$'),"
                    . "CONSTRAINT bibliographic_discovery_candidate_json_valid CHECK (JSON_VALID(candidate_json) AND CHAR_LENGTH(candidate_json) <= 32768),"
                    . "CONSTRAINT bibliographic_discovery_candidate_hash_valid CHECK (candidate_hash REGEXP '^[0-9a-f]{64}$'),"
                    . "CONSTRAINT bibliographic_discovery_candidate_hash_matches CHECK (BINARY candidate_hash=BINARY SHA2(candidate_json,256))"
                    . ") ENGINE=InnoDB {$collation}",
                "bibliographic discovery candidates"
            );
        }

        if (!$this->tableExists($identities)) {
            $this->execute(
                "CREATE TABLE `{$identities}` ("
                    . "provider_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "source_entity_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "provider_record_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "target_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "work_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
                    . "edition_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
                    . "PRIMARY KEY (provider_key,source_entity_type,provider_record_id,target_type),"
                    . "KEY bibliographic_provider_identity_by_work (work_id,provider_key,source_entity_type,provider_record_id),"
                    . "KEY bibliographic_provider_identity_by_edition (edition_id,provider_key,provider_record_id),"
                    . "CONSTRAINT bibliographic_provider_identity_work_fk FOREIGN KEY (work_id) REFERENCES `{$works}` (work_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT bibliographic_provider_identity_edition_fk FOREIGN KEY (edition_id) REFERENCES `{$editions}` (edition_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT bibliographic_provider_identity_provider_valid CHECK (provider_key REGEXP '^[a-z][a-z0-9_]{0,63}$'),"
                    . "CONSTRAINT bibliographic_provider_identity_source_valid CHECK (source_entity_type IN ('work','edition')),"
                    . "CONSTRAINT bibliographic_provider_identity_record_valid CHECK (CHAR_LENGTH(TRIM(provider_record_id)) > 0),"
                    . "CONSTRAINT bibliographic_provider_identity_target_valid CHECK ((target_type='work' AND work_id IS NOT NULL AND edition_id IS NULL) OR (target_type='edition' AND work_id IS NULL AND edition_id IS NOT NULL))"
                    . ") ENGINE=InnoDB {$collation}",
                "bibliographic provider identities"
            );
        }

        if (!$this->evidenceAlreadyGeneralized()) {
            $evidence = $this->tables->metadataFieldEvidence();
            $this->execute(
                "ALTER TABLE `{$evidence}` "
                    . "DROP CONSTRAINT metadata_field_evidence_match_supported,"
                    . "DROP CONSTRAINT metadata_field_evidence_query_valid,"
                    . "MODIFY queried_identifier VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "ADD CONSTRAINT metadata_field_evidence_match_supported CHECK (match_method IN ('exact_isbn','text_search')),"
                    . "ADD CONSTRAINT metadata_field_evidence_query_valid CHECK ((queried_identifier_type='isbn_10' AND queried_identifier REGEXP '^[0-9]{9}[0-9X]$') OR (queried_identifier_type='isbn_13' AND queried_identifier REGEXP '^97[89][0-9]{10}$') OR (queried_identifier_type='text' AND CHAR_LENGTH(TRIM(queried_identifier)) > 0 AND CHAR_LENGTH(queried_identifier) <= 100))",
                "generic metadata evidence query"
            );
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->health->inspectForVersion(1023);
        if (!$health->isHealthy()) { throw new CoreSchemaHealthException($health); }
    }

    private function evidenceAlreadyGeneralized(): bool
    {
        $type = $this->database->get_var($this->database->prepare(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='queried_identifier'",
            DB_NAME,
            $this->tables->metadataFieldEvidence()
        ));
        return strtolower((string) $type) === "varchar(100)";
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME,
            $table
        )) === 1;
    }

    private function execute(string $sql, string $component): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not migrate schema 1023 component {$component}: "
                    . $this->database->last_error
            );
        }
    }
}
