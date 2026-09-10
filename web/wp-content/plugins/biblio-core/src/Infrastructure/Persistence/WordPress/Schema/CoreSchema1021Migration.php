<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1021Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $health;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
        $this->health = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1020; }
    public function targetVersion(): int { return 1021; }

    public function assertPrecondition(): void
    {
        $markers = [
            $this->hasColumn($this->tables->ratings(), "assessed_at"),
            $this->hasColumn($this->tables->reviews(), "assessed_at"),
        ];
        $markerCount = count(array_filter($markers));

        if ($markerCount === 0) {
            $source = $this->health->inspectForVersion(1020);
            if (!$source->isHealthy()) {
                throw new CoreSchemaHealthException($source);
            }
            return;
        }

        $target = $this->health->inspectSchema1021AssessmentTimes();
        if ($markerCount !== count($markers) || !$target->isHealthy()) {
            throw new CoreSchemaMigrationException(
                "Schema 1021 retry found an unknown assessment-time state: "
                    . $target->summary()
            );
        }
    }

    public function migrate(): void
    {
        $ratings = $this->tables->ratings();
        $reviews = $this->tables->reviews();
        if (!$this->health->inspectSchema1021AssessmentTimes()->isHealthy()) {
            $this->execute(
                "ALTER TABLE `{$ratings}` ADD assessed_at DATETIME(6) NULL "
                    . "AFTER rating_half_units",
                "Rating assessment time"
            );
            $this->execute(
                "ALTER TABLE `{$reviews}` ADD assessed_at DATETIME(6) NULL "
                    . "AFTER review_content",
                "Review assessment time"
            );
        }

        // Before 1021 every supported V2 creation wrote the current business
        // assessment and technical creation instant together; historical import
        // did not yet exist. Preserve that known meaning for existing rows. Run
        // this on a healthy-column retry too: the first attempt may have stopped
        // after both ALTER statements but before completing the backfill.
        $this->execute(
            "UPDATE `{$ratings}` SET assessed_at=created_at "
                . "WHERE assessed_at IS NULL",
            "existing Rating assessment times"
        );
        $this->execute(
            "UPDATE `{$reviews}` SET assessed_at=created_at "
                . "WHERE assessed_at IS NULL",
            "existing Review assessment times"
        );
    }

    public function assertPostcondition(): void
    {
        $health = $this->health->inspectForVersion(1021);
        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    private function execute(string $sql, string $component): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not migrate schema 1021 component {$component}: "
                    . $this->database->last_error
            );
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s",
            DB_NAME,
            $table,
            $column
        )) === 1;
    }
}
