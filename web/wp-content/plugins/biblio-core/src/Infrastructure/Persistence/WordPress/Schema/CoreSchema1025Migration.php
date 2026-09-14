<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1025Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $health;

    public function __construct(private wpdb $database, private CoreTableNames $tables)
    {
        $this->health = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1024; }
    public function targetVersion(): int { return 1025; }

    public function assertPrecondition(): void
    {
        $source = $this->health->inspectForVersion(1024);
        if (!$source->isHealthy()) {
            throw new CoreSchemaHealthException($source);
        }
        $addition = $this->health->inspectExistingSchema1025Additions();
        if (!$addition->isHealthy()) {
            throw new CoreSchemaMigrationException(
                "Schema 1025 retry found an unknown Item-local details state: "
                    . $addition->summary()
            );
        }
    }

    public function migrate(): void
    {
        $details = $this->tables->itemLocalDetails();
        if ($this->tableExists($details)) {
            return;
        }
        $items = $this->tables->items();
        $collation = $this->database->get_charset_collate();
        $sql = "CREATE TABLE `{$details}` ("
            . "library_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
            . "item_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
            . "condition_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "acquisition_year SMALLINT UNSIGNED NULL,"
            . "acquisition_month TINYINT UNSIGNED NULL,"
            . "acquisition_day TINYINT UNSIGNED NULL,"
            . "acquisition_method VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "acquired_via VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
            . "paid_amount DECIMAL(19,4) UNSIGNED NULL,"
            . "paid_currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "signed TINYINT UNSIGNED NULL,"
            . "signed_by VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
            . "copy_limitation VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
            . "dust_jacket VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "inscription TINYINT UNSIGNED NULL,"
            . "provenance VARCHAR(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
            . "completeness VARCHAR(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
            . "details_version BIGINT UNSIGNED NOT NULL,"
            . "PRIMARY KEY (library_id,item_id),"
            . "KEY item_details_by_library_acquisition_method (library_id,acquisition_method,item_id),"
            . "CONSTRAINT item_local_details_item_fk FOREIGN KEY (library_id,item_id) REFERENCES `{$items}` (library_id,item_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
            . "CONSTRAINT item_details_condition_valid CHECK (condition_code IS NULL OR condition_code IN ('nieuwstaat','zeer_goed','goed','redelijk','matig','slecht')),"
            . "CONSTRAINT item_details_date_valid CHECK ((acquisition_year IS NULL AND acquisition_month IS NULL AND acquisition_day IS NULL) OR (acquisition_year IS NOT NULL AND acquisition_year BETWEEN 1000 AND 9999 AND ((acquisition_month IS NULL AND acquisition_day IS NULL) OR (acquisition_month IS NOT NULL AND acquisition_month BETWEEN 1 AND 12 AND (acquisition_day IS NULL OR (acquisition_day IS NOT NULL AND acquisition_day BETWEEN 1 AND CASE WHEN acquisition_month=2 THEN 28+(MOD(acquisition_year,400)=0 OR MOD(acquisition_year,4)=0 AND MOD(acquisition_year,100)<>0) WHEN acquisition_month IN (4,6,9,11) THEN 30 ELSE 31 END)))))),"
            . "CONSTRAINT item_details_acquisition_method_valid CHECK (acquisition_method IS NULL OR acquisition_method IN ('zelf_aangeschaft','gekregen','anders')),"
            . "CONSTRAINT item_details_amount_pair_valid CHECK ((paid_amount IS NULL)=(paid_currency IS NULL)),"
            . "CONSTRAINT item_details_currency_shape_valid CHECK (paid_currency IS NULL OR paid_currency REGEXP '^[A-Z]{3}$'),"
            . "CONSTRAINT item_details_signed_valid CHECK (signed IS NULL OR signed IN (0,1)),"
            . "CONSTRAINT item_details_signer_valid CHECK (signed_by IS NULL OR signed IS NOT NULL AND signed=1),"
            . "CONSTRAINT item_details_dust_jacket_valid CHECK (dust_jacket IS NULL OR dust_jacket IN ('present','missing','not_applicable')),"
            . "CONSTRAINT item_details_inscription_valid CHECK (inscription IS NULL OR inscription IN (0,1)),"
            . "CONSTRAINT item_details_text_valid CHECK ((acquired_via IS NULL OR CHAR_LENGTH(TRIM(acquired_via))>0 AND acquired_via NOT REGEXP '[[:cntrl:]]') AND (signed_by IS NULL OR CHAR_LENGTH(TRIM(signed_by))>0 AND signed_by NOT REGEXP '[[:cntrl:]]') AND (copy_limitation IS NULL OR CHAR_LENGTH(TRIM(copy_limitation))>0 AND copy_limitation NOT REGEXP '[[:cntrl:]]') AND (provenance IS NULL OR CHAR_LENGTH(TRIM(provenance))>0 AND provenance NOT REGEXP '[[:cntrl:]]') AND (completeness IS NULL OR CHAR_LENGTH(TRIM(completeness))>0 AND completeness NOT REGEXP '[[:cntrl:]]')),"
            . "CONSTRAINT item_details_version_positive CHECK (details_version>=1)"
            . ") ENGINE=InnoDB {$collation}";
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not create schema 1025 Item-local details: " . $this->database->last_error
            );
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->health->inspectForVersion(1025);
        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME,
            $table
        )) === 1;
    }
}
