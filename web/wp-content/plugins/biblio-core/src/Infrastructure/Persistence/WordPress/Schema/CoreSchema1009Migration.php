<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1009Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $healthChecker;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
        $this->healthChecker = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1008; }
    public function targetVersion(): int { return 1009; }

    public function assertPrecondition(): void
    {
        $health = $this->healthChecker->inspectForVersion(1008);
        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
        $partial = $this->healthChecker->inspectExistingSchema1009Additions();
        if (!$partial->isHealthy()) {
            throw new CoreSchemaMigrationException(
                "Schema 1009 retry found an unknown Author/Series state: "
                    . $partial->summary()
            );
        }
    }

    public function migrate(): void
    {
        foreach ($this->definitions() as $table => $sql) {
            if (!$this->tableExists($table)) {
                $this->execute($sql, $table);
            }
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->healthChecker->inspectForVersion(1009);
        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    /** @return array<string, string> */
    private function definitions(): array
    {
        $authors = $this->tables->authors();
        $contributors = $this->tables->workContributors();
        $series = $this->tables->series();
        $workSeries = $this->tables->workSeries();
        $works = $this->tables->works();
        $collation = $this->database->get_charset_collate();

        return [
            $authors => "CREATE TABLE `{$authors}` ("
                . "author_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "display_name VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "PRIMARY KEY (author_id),"
                . "KEY authors_by_display_name (display_name,author_id),"
                . "CONSTRAINT authors_name_non_empty CHECK (CHAR_LENGTH(TRIM(display_name)) > 0)"
                . ") ENGINE=InnoDB {$collation}",
            $series => "CREATE TABLE `{$series}` ("
                . "series_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "display_name VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "PRIMARY KEY (series_id),"
                . "KEY series_by_display_name (display_name,series_id),"
                . "CONSTRAINT series_name_non_empty CHECK (CHAR_LENGTH(TRIM(display_name)) > 0)"
                . ") ENGINE=InnoDB {$collation}",
            $contributors => "CREATE TABLE `{$contributors}` ("
                . "work_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "author_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "contributor_role VARCHAR(32) NOT NULL,"
                . "contributor_position BIGINT UNSIGNED NOT NULL,"
                . "PRIMARY KEY (work_id,author_id),"
                . "UNIQUE KEY work_contributors_by_position (work_id,contributor_position),"
                . "KEY work_contributors_by_author (author_id,work_id),"
                . "CONSTRAINT work_contributors_work_fk FOREIGN KEY (work_id) REFERENCES `{$works}` (work_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                . "CONSTRAINT work_contributors_author_fk FOREIGN KEY (author_id) REFERENCES `{$authors}` (author_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                . "CONSTRAINT work_contributors_role_valid CHECK (contributor_role IN ('author','co_author')),"
                . "CONSTRAINT work_contributors_position_positive CHECK (contributor_position >= 1)"
                . ") ENGINE=InnoDB {$collation}",
            $workSeries => "CREATE TABLE `{$workSeries}` ("
                . "work_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "series_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "series_position DECIMAL(20,6) UNSIGNED NULL,"
                . "PRIMARY KEY (work_id,series_id),"
                . "KEY work_series_by_series_order (series_id,series_position,work_id),"
                . "CONSTRAINT work_series_work_fk FOREIGN KEY (work_id) REFERENCES `{$works}` (work_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                . "CONSTRAINT work_series_series_fk FOREIGN KEY (series_id) REFERENCES `{$series}` (series_id) ON UPDATE RESTRICT ON DELETE RESTRICT"
                . ") ENGINE=InnoDB {$collation}",
        ];
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME,
            $table
        )) === 1;
    }

    private function execute(string $sql, string $table): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not create schema 1009 table {$table}: "
                    . $this->database->last_error
            );
        }
    }
}
