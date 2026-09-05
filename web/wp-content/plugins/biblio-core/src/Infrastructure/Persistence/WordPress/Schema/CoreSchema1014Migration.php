<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1014Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $health;
    private WpdbIsbnIntegrityAuditor $auditor;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
        $this->health = new CoreSchemaHealthChecker($database, $tables);
        $this->auditor = new WpdbIsbnIntegrityAuditor($database, $tables);
    }

    public function sourceVersion(): int { return 1013; }
    public function targetVersion(): int { return 1014; }

    public function assertPrecondition(): void
    {
        $source = $this->health->inspectForVersion(1013);
        if (!$source->isHealthy()) {
            throw new CoreSchemaHealthException($source);
        }

        $audit = $this->auditor->audit();
        if ($audit->hasBlockers()) {
            throw new CoreSchemaMigrationException(
                "Schema 1014 ISBN audit blocked migration: "
                    . $audit->blockerSummary()
            );
        }

        $additions = $this->health->inspectExistingSchema1014Additions();
        if (!$additions->isHealthy()) {
            throw new CoreSchemaMigrationException(
                "Schema 1014 retry found an unknown Metadata identity state: "
                    . $additions->summary()
            );
        }
    }

    public function migrate(): void
    {
        $collation = $this->database->get_charset_collate();
        $editions = $this->tables->editions();
        $claims = $this->tables->editionIdentifierClaims();
        if (!$this->tableExists($claims)) {
            $this->execute(
                "CREATE TABLE `{$claims}` ("
                    . "canonical_isbn_13 CHAR(13) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "edition_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "PRIMARY KEY (canonical_isbn_13),"
                    . "UNIQUE KEY edition_identifier_claim_one_per_edition (edition_id),"
                    . "CONSTRAINT edition_identifier_claim_edition_fk FOREIGN KEY (edition_id) REFERENCES `{$editions}` (edition_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT canonical_isbn13_shape_valid CHECK (canonical_isbn_13 REGEXP '^97[89][0-9]{10}$')"
                    . ") ENGINE=InnoDB {$collation}",
                "Edition identifier claims"
            );
        }

        $provenance = $this->tables->editionMetadataProvenance();
        if (!$this->tableExists($provenance)) {
            $this->execute(
                "CREATE TABLE `{$provenance}` ("
                    . "provenance_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "edition_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "provider_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "provider_record_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "retrieved_at DATETIME(6) NOT NULL,"
                    . "match_method VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "queried_identifier_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "queried_identifier VARCHAR(13) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "confirmation_state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "PRIMARY KEY (provenance_id),"
                    . "UNIQUE KEY edition_metadata_provenance_identity (edition_id,provider_key,provider_record_id,queried_identifier),"
                    . "KEY edition_metadata_provenance_by_time (edition_id,retrieved_at,provenance_id),"
                    . "CONSTRAINT edition_metadata_provenance_edition_fk FOREIGN KEY (edition_id) REFERENCES `{$editions}` (edition_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT metadata_provider_key_non_empty CHECK (CHAR_LENGTH(TRIM(provider_key)) > 0),"
                    . "CONSTRAINT metadata_provider_record_non_empty CHECK (CHAR_LENGTH(TRIM(provider_record_id)) > 0),"
                    . "CONSTRAINT metadata_match_method_supported CHECK (match_method = 'exact_isbn'),"
                    . "CONSTRAINT metadata_query_identifier_valid CHECK ((queried_identifier_type='isbn_10' AND queried_identifier REGEXP '^[0-9]{9}[0-9X]$') OR (queried_identifier_type='isbn_13' AND queried_identifier REGEXP '^97[89][0-9]{10}$')),"
                    . "CONSTRAINT metadata_confirmation_state_supported CHECK (confirmation_state IN ('accepted_unchanged','accepted_corrected'))"
                    . ") ENGINE=InnoDB {$collation}",
                "Edition metadata provenance"
            );
        }

        $this->backfillCanonicalClaims();
    }

    public function assertPostcondition(): void
    {
        $health = $this->health->inspectForVersion(1014);
        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    private function backfillCanonicalClaims(): void
    {
        $claims = $this->tables->editionIdentifierClaims();
        $expected = $this->auditor->audit()->canonicalClaims;
        $actual = $this->database->get_results(
            "SELECT canonical_isbn_13,edition_id FROM `{$claims}`",
            ARRAY_A
        );

        foreach ($actual as $row) {
            $isbn = (string) $row["canonical_isbn_13"];
            $editionId = (string) $row["edition_id"];
            if (($expected[$isbn] ?? null) !== $editionId) {
                throw new CoreSchemaMigrationException(
                    "Existing canonical ISBN claim does not match Edition metadata."
                );
            }
            unset($expected[$isbn]);
        }

        foreach ($expected as $isbn => $editionId) {
            $result = $this->database->insert(
                $claims,
                ["canonical_isbn_13" => $isbn, "edition_id" => $editionId],
                ["%s", "%s"]
            );
            if ($result !== 1) {
                throw new CoreSchemaMigrationException(
                    "Could not backfill canonical ISBN claim: "
                        . $this->database->last_error
                );
            }
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
                "Could not create schema 1014 component {$component}: "
                    . $this->database->last_error
            );
        }
    }
}
