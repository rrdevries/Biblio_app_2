<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1027Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $health;

    public function __construct(private wpdb $database, private CoreTableNames $tables)
    {
        $this->health = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1026; }
    public function targetVersion(): int { return 1027; }

    public function assertPrecondition(): void
    {
        $source = $this->health->inspectForVersion(1026);
        if (!$source->isHealthy()) { throw new CoreSchemaHealthException($source); }
        $additions = $this->health->inspectExistingSchema1027Additions();
        if (!$additions->isHealthy()) { throw new CoreSchemaHealthException($additions); }
    }

    public function migrate(): void
    {
        $table = $this->tables->accountPreparations();
        if ((int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s", $table
        )) > 0) { return; }
        $libraries = $this->tables->libraries();
        $collation = $this->database->get_charset_collate();
        $sql = "CREATE TABLE `{$table}` ("
            . "user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
            . "library_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
            . "naming_complete TINYINT(1) NOT NULL DEFAULT 0,notification_sent TINYINT(1) NOT NULL DEFAULT 0,"
            . "PRIMARY KEY (user_id),UNIQUE KEY one_preparation_per_library (library_id),"
            . "CONSTRAINT account_preparation_library_fk FOREIGN KEY (library_id) REFERENCES `{$libraries}` (library_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
            . "CONSTRAINT account_preparation_flags CHECK (naming_complete IN (0,1) AND notification_sent IN (0,1)),"
            . "CONSTRAINT account_preparation_pending CHECK (library_id IS NOT NULL OR naming_complete=0 AND notification_sent=0),"
            . "CONSTRAINT account_preparation_user CHECK (CHAR_LENGTH(TRIM(user_id)) > 0)"
            . ") ENGINE=InnoDB {$collation}";
        if ($this->database->query($sql) === false) { throw new CoreSchemaMigrationException("Could not create account preparations: " . $this->database->last_error); }
    }

    public function assertPostcondition(): void
    {
        $health = $this->health->inspectForVersion(1027);
        if (!$health->isHealthy()) { throw new CoreSchemaHealthException($health); }
    }
}
