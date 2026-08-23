<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaHealthException;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationException;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationRegistry;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;
use RuntimeException;
use wpdb;

final class RetryableProbeMigration implements CoreSchemaMigration
{
    public const INDEX_NAME = "f1_1_migration_probe_by_title";

    public function __construct(
        private readonly wpdb $database,
        private readonly CoreTableNames $tableNames,
        private bool $failAfterKnownChange = true
    ) {
    }

    public function sourceVersion(): int
    {
        return CoreSchemaMigrator::CURRENT_VERSION;
    }

    public function targetVersion(): int
    {
        return CoreSchemaMigrator::CURRENT_VERSION + 1;
    }

    public function assertPrecondition(): void
    {
        if (!$this->tableExists($this->tableNames->works())) {
            throw new RuntimeException("Probe migration requires Works table.");
        }

        if (
            $this->indexExists()
            && $this->indexColumns() !== ["work_title"]
        ) {
            throw new RuntimeException(
                "Probe index exists with an unexpected definition."
            );
        }
    }

    public function migrate(): void
    {
        if (!$this->indexExists()) {
            $works = $this->tableNames->works();
            $result = $this->database->query(
                "ALTER TABLE `{$works}` ADD INDEX `" . self::INDEX_NAME
                . "` (work_title)"
            );

            if ($result === false) {
                throw new RuntimeException(
                    "Could not add probe index: "
                    . $this->database->last_error
                );
            }
        }

        if ($this->failAfterKnownChange) {
            $this->failAfterKnownChange = false;
            throw new RuntimeException(
                "Forced failure after known rerunnable DDL."
            );
        }
    }

    public function assertPostcondition(): void
    {
        if (!$this->indexExists() || $this->indexColumns() !== ["work_title"]) {
            throw new RuntimeException("Probe migration postcondition failed.");
        }
    }

    public function removeProbeIndex(): void
    {
        if (!$this->indexExists()) {
            return;
        }

        $works = $this->tableNames->works();
        $this->database->query(
            "ALTER TABLE `{$works}` DROP INDEX `" . self::INDEX_NAME . "`"
        );
    }

    private function indexExists(): bool
    {
        return $this->indexColumns() !== [];
    }

    /** @return list<string> */
    private function indexColumns(): array
    {
        return $this->database->get_col($this->database->prepare(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s "
            . "AND INDEX_NAME = %s ORDER BY SEQ_IN_INDEX",
            DB_NAME,
            $this->tableNames->works(),
            self::INDEX_NAME
        ));
    }

    private function tableExists(string $tableName): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $tableName
        )) === 1;
    }
}

final class CoreSchemaMigrationTest extends PersistenceIntegrationTestCase
{
    public function testFreshDatabaseInstallsBaselineAndMigratesToCurrent(): void
    {
        $expectedBaseline = $this->baselineSchemaSnapshot();
        $this->dropCoreSchema();

        $migrator = $this->migrator();
        $migrator->migrate();

        self::assertSame(
            CoreSchemaMigrator::CURRENT_VERSION,
            $migrator->installedVersion()
        );
        self::assertSame(
            CoreSchemaMigrator::CURRENT_VERSION,
            $migrator->expectedVersion()
        );
        self::assertTrue(
            $migrator->healthForVersion(1005)->isHealthy(),
            $migrator->healthForVersion(1005)->summary()
        );
        self::assertSame(8, $this->existingCoreTableCount());
        self::assertSame(21, $this->existingCurrentTableCount());
        self::assertSame($expectedBaseline, $this->baselineSchemaSnapshot());
        self::assertSame(
            "STORED GENERATED",
            $this->columnExtra(
                $this->tableNames->readingRounds(),
                "active_item_user_id"
            )
        );
        self::assertSame(
            "UNIQUE",
            $this->constraintType(
                $this->tableNames->readingRounds(),
                "one_active_external_round_per_user"
            )
        );
        self::assertSame(
            3,
            $this->foreignKeyCount($this->tableNames->readingRounds())
        );
        self::assertGreaterThanOrEqual(
            2,
            $this->checkConstraintCount($this->tableNames->readingRounds())
        );
    }

    public function testFreshInstallCanContinueWithExplicitFutureMigration(): void
    {
        $this->dropCoreSchema();
        $migration = new RetryableProbeMigration(
            $this->database,
            $this->tableNames,
            false
        );
        $migrator = $this->migrator([$migration]);

        try {
            $migrator->migrate();

            self::assertSame(1007, $migrator->installedVersion());
            self::assertSame(1007, $migrator->expectedVersion());
            self::assertTrue($migrator->healthForVersion(1005)->isHealthy());
            self::assertSame(21, $this->existingCurrentTableCount());
            self::assertSame(1, $this->indexCount(
                $this->tableNames->works(),
                RetryableProbeMigration::INDEX_NAME
            ));
        } finally {
            $migration->removeProbeIndex();
            update_option(
                CoreSchemaMigrator::VERSION_OPTION,
                (string) CoreSchemaMigrator::CURRENT_VERSION,
                false
            );
        }
    }

    public function testHealthRequiresExplicitContractForRequestedVersion(): void
    {
        $this->expectException(CoreSchemaMigrationException::class);
        $this->expectExceptionMessage(
            "No explicit Biblio Core schema-health contract exists"
        );

        $this->migrator()->healthForVersion(1007);
    }

    public function testHealthyCurrentRunIsSchemaAndDataNoOp(): void
    {
        $this->insertRepresentativeSentinelData();
        $schemaBefore = $this->schemaSnapshot();
        $dataBefore = $this->dataSnapshot();

        $this->migrator()->migrate();

        self::assertSame($schemaBefore, $this->schemaSnapshot());
        self::assertSame($dataBefore, $this->dataSnapshot());
        self::assertSame(
            CoreSchemaMigrator::CURRENT_VERSION,
            $this->migrator()->installedVersion()
        );
    }

    public function testFailedForwardMigrationDoesNotBumpAndRetryPreservesData(): void
    {
        $works = $this->tableNames->works();
        $this->database->insert(
            $works,
            ["work_id" => "sentinel-work", "work_title" => "Sentinel"],
            ["%s", "%s"]
        );
        $migration = new RetryableProbeMigration(
            $this->database,
            $this->tableNames
        );
        $migrator = $this->migrator([$migration]);

        try {
            $migrator->migrate();
            self::fail("Forced forward-migration failure did not occur.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertSame(
                FailureReason::SchemaMigrationFailed,
                $exception->reason()
            );
            self::assertStringContainsString(
                "failed before the version bump",
                $exception->getMessage()
            );
            self::assertStringContainsString(
                "Forced failure after known rerunnable DDL",
                $exception->getMessage()
            );
            self::assertSame(
                CoreSchemaMigrator::CURRENT_VERSION,
                $migrator->installedVersion()
            );
            self::assertSame(
                "Sentinel",
                $this->database->get_var($this->database->prepare(
                    "SELECT work_title FROM `{$works}` WHERE work_id = %s",
                    "sentinel-work"
                ))
            );
        }

        try {
            $migrator->migrate();

            self::assertSame(
                CoreSchemaMigrator::CURRENT_VERSION + 1,
                $migrator->installedVersion()
            );
            self::assertSame(1, $this->indexCount(
                $works,
                RetryableProbeMigration::INDEX_NAME
            ));
            self::assertSame(
                "Sentinel",
                $this->database->get_var($this->database->prepare(
                    "SELECT work_title FROM `{$works}` WHERE work_id = %s",
                    "sentinel-work"
                ))
            );
        } finally {
            $migration->removeProbeIndex();
            update_option(
                CoreSchemaMigrator::VERSION_OPTION,
                (string) CoreSchemaMigrator::CURRENT_VERSION,
                false
            );
        }
    }

    public function testCurrentVersionWithMissingTableFailsClosed(): void
    {
        $table = $this->tableNames->privateNotes();
        $createSql = $this->showCreateTable($table);
        $this->database->query("DROP TABLE `{$table}`");

        try {
            $health = $this->migrator()->health();
            self::assertFalse($health->isHealthy());
            self::assertStringContainsString(
                "Missing required table {$table}",
                $health->summary()
            );

            $this->expectSchemaHealthFailure(
                "Missing required table {$table}"
            );
            self::assertFalse($this->tableExists($table));
        } finally {
            $this->database->query($createSql);
        }
    }

    public function testCurrentVersionWithMissingUniqueIndexFailsClosed(): void
    {
        $table = $this->tableNames->readingRounds();
        $index = "one_active_external_round_per_user";
        $this->database->query(
            "ALTER TABLE `{$table}` DROP INDEX `{$index}`"
        );

        try {
            $health = $this->migrator()->health();
            self::assertFalse($health->isHealthy());
            self::assertStringContainsString(
                "missing required index {$index}",
                $health->summary()
            );
            $this->expectSchemaHealthFailure(
                "missing required index {$index}"
            );
            self::assertSame(0, $this->indexCount($table, $index));
        } finally {
            $this->database->query(
                "ALTER TABLE `{$table}` ADD UNIQUE KEY `{$index}` "
                . "(active_external_loan_user_id, external_loan_id)"
            );
        }
    }

    public function testCurrentVersionWithMissingXorCheckFailsClosed(): void
    {
        $table = $this->tableNames->readingRounds();
        $constraint = $this->findCheckConstraint(
            $table,
            "item_id",
            "external_loan_id"
        );
        self::assertNotNull($constraint);
        $this->database->query(
            "ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`"
        );

        try {
            $health = $this->migrator()->health();
            self::assertFalse($health->isHealthy());
            self::assertStringContainsString(
                "missing required CHECK",
                $health->summary()
            );
            $this->expectSchemaHealthFailure("missing required CHECK");
            self::assertNull($this->findCheckConstraint(
                $table,
                "item_id",
                "external_loan_id"
            ));
        } finally {
            $this->database->query(
                "ALTER TABLE `{$table}` ADD CONSTRAINT "
                . "`reading_rounds_source_shape` CHECK "
                . "(item_id IS NULL OR external_loan_id IS NULL)"
            );
        }
    }

    public function testCheckLiteralWhitespaceDriftFailsClosed(): void
    {
        $table = $this->tableNames->memberships();
        $constraint = "memberships_status_valid";
        $this->database->query(
            "ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`"
        );
        $this->database->query(
            "ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` "
            . "CHECK (membership_status IN ('active', 'in active'))"
        );

        try {
            $this->expectSchemaHealthFailure("missing required CHECK");
        } finally {
            $this->database->query(
                "ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`"
            );
            $this->database->query(
                "ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` "
                . "CHECK (membership_status IN ('active', 'inactive'))"
            );
        }
    }

    public function testPartialBaselineWithoutVersionFailsClosed(): void
    {
        $libraries = $this->tableNames->libraries();
        $librarySql = $this->showCreateTable($libraries);
        $this->dropCoreSchema();
        $this->database->query($librarySql);

        try {
            $this->expectSchemaHealthFailure(
                "requires an empty Core schema"
            );
            self::assertTrue($this->tableExists($libraries));
            self::assertFalse($this->tableExists(
                $this->tableNames->memberships()
            ));
            self::assertNull($this->migrator()->installedVersion());
        } finally {
            $this->database->query("DROP TABLE `{$libraries}`");
            $this->migrator()->migrate();
        }
    }

    public function testLegacySpikeVersionRequiresDevelopmentRebuild(): void
    {
        $this->dropCoreSchema();
        update_option(CoreSchemaMigrator::LEGACY_VERSION_OPTION, "5", false);

        try {
            $this->migrator()->migrate();
            self::fail("Legacy spike version was silently adopted.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertSame(
                FailureReason::SchemaMigrationFailed,
                $exception->reason()
            );
            self::assertStringContainsString(
                "not a supported production migration source",
                $exception->getMessage()
            );
            self::assertSame(0, $this->existingCoreTableCount());
            self::assertNull($this->migrator()->installedVersion());
        } finally {
            delete_option(CoreSchemaMigrator::LEGACY_VERSION_OPTION);
            $this->migrator()->migrate();
        }
    }

    /** @param list<CoreSchemaMigration> $migrations */
    private function migrator(array $migrations = []): CoreSchemaMigrator
    {
        $productionMigrations = CoreSchemaMigrationRegistry::production(
            $this->database,
            $this->tableNames
        )->migrations();

        return new CoreSchemaMigrator(
            $this->database,
            $this->tableNames,
            [...$productionMigrations, ...$migrations]
        );
    }

    private function expectSchemaHealthFailure(string $diagnostic): void
    {
        try {
            $this->migrator()->migrate();
            self::fail("Unexpected schema drift was silently accepted.");
        } catch (CoreSchemaHealthException $exception) {
            self::assertSame(
                FailureReason::SchemaHealthFailed,
                $exception->reason()
            );
            self::assertStringContainsString(
                "no automatic repair was attempted",
                $exception->getMessage()
            );
            self::assertStringContainsString(
                $diagnostic,
                $exception->getMessage()
            );
        }
    }

    private function insertRepresentativeSentinelData(): void
    {
        $this->database->insert($this->tableNames->libraries(), [
            "library_id" => "sentinel-library",
            "library_type" => "private_library",
            "library_status" => "active",
        ]);
        $this->database->insert($this->tableNames->memberships(), [
            "library_id" => "sentinel-library",
            "user_id" => "sentinel-user",
            "membership_status" => "active",
            "management_role" => "owner",
            "use_access" => "direct",
            "additional_permissions" => "[]",
        ]);
        $this->database->insert(
            $this->tableNames->personalLibraryDesignations(),
            [
                "user_id" => "sentinel-user",
                "library_id" => "sentinel-library",
            ]
        );
        $this->database->insert($this->tableNames->works(), [
            "work_id" => "sentinel-work",
            "work_title" => "Sentinel Work",
        ]);
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => "sentinel-edition",
            "work_id" => "sentinel-work",
        ]);
        $this->database->insert($this->tableNames->items(), [
            "item_id" => "sentinel-item",
            "library_id" => "sentinel-library",
            "edition_id" => "sentinel-edition",
            "item_status" => "active",
        ]);
        $this->database->insert($this->tableNames->externalLoans(), [
            "external_loan_id" => "sentinel-loan",
            "user_id" => "sentinel-user",
            "work_id" => "sentinel-work",
            "loan_status" => "active",
            "borrowed_at" => "2026-08-17 08:00:00.000000",
            "due_at" => null,
        ]);
        $this->database->insert($this->tableNames->readingRounds(), [
            "reading_round_id" => "sentinel-round",
            "user_id" => "sentinel-user",
            "work_id" => "sentinel-work",
            "item_id" => "sentinel-item",
            "external_loan_id" => null,
            "started_at" => null,
            "round_outcome" => null,
            "provenance" => "source_started",
            "reading_started_year" => 2026,
            "reading_started_month" => 8,
            "reading_started_day" => 17,
            "reading_finished_year" => null,
            "reading_finished_month" => null,
            "reading_finished_day" => null,
            "created_at" => "2026-08-17 09:00:00.000000",
            "updated_at" => "2026-08-17 09:00:00.000000",
            "ended_at" => null,
            "round_version" => 1,
        ]);
    }

    /** @return array<string, string> */
    private function baselineSchemaSnapshot(): array
    {
        $snapshot = [];

        foreach ($this->tableNames->all() as $tableName) {
            $snapshot[$tableName] = $this->showCreateTable($tableName);
        }

        return $snapshot;
    }

    /** @return array<string, string> */
    private function schemaSnapshot(): array
    {
        $snapshot = [];

        foreach ($this->tableNames->schema1006() as $tableName) {
            $snapshot[$tableName] = $this->showCreateTable($tableName);
        }

        return $snapshot;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function dataSnapshot(): array
    {
        $snapshot = [];

        foreach ($this->tableNames->schema1006() as $tableName) {
            $snapshot[$tableName] = $this->database->get_results(
                "SELECT * FROM `{$tableName}`",
                ARRAY_A
            );
        }

        return $snapshot;
    }

    private function dropCoreSchema(): void
    {
        foreach (array_reverse($this->tableNames->schema1006()) as $tableName) {
            $this->database->query("DROP TABLE IF EXISTS `{$tableName}`");
        }

        delete_option(CoreSchemaMigrator::VERSION_OPTION);
        delete_option(CoreSchemaMigrator::LEGACY_VERSION_OPTION);
    }

    private function existingCoreTableCount(): int
    {
        $count = 0;

        foreach ($this->tableNames->all() as $tableName) {
            $count += $this->tableExists($tableName) ? 1 : 0;
        }

        return $count;
    }

    private function existingCurrentTableCount(): int
    {
        $count = 0;

        foreach ($this->tableNames->schema1006() as $tableName) {
            $count += $this->tableExists($tableName) ? 1 : 0;
        }

        return $count;
    }

    private function tableExists(string $tableName): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $tableName
        )) === 1;
    }

    private function showCreateTable(string $tableName): string
    {
        $row = $this->database->get_row(
            "SHOW CREATE TABLE `{$tableName}`",
            ARRAY_N
        );

        if (!is_array($row) || !isset($row[1])) {
            throw new RuntimeException(
                "Could not inspect table {$tableName}."
            );
        }

        return $row[1];
    }

    private function columnExtra(string $tableName, string $columnName): string
    {
        return (string) $this->database->get_var($this->database->prepare(
            "SELECT EXTRA FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s "
            . "AND COLUMN_NAME = %s",
            DB_NAME,
            $tableName,
            $columnName
        ));
    }

    private function constraintType(
        string $tableName,
        string $constraintName
    ): string {
        return (string) $this->database->get_var($this->database->prepare(
            "SELECT CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME = %s "
            . "AND CONSTRAINT_NAME = %s",
            DB_NAME,
            $tableName,
            $constraintName
        ));
    }

    private function foreignKeyCount(string $tableName): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME = %s "
            . "AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            DB_NAME,
            $tableName
        ));
    }

    private function checkConstraintCount(string $tableName): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME = %s "
            . "AND CONSTRAINT_TYPE = 'CHECK'",
            DB_NAME,
            $tableName
        ));
    }

    private function indexCount(string $tableName, string $indexName): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(DISTINCT INDEX_NAME) "
            . "FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s "
            . "AND INDEX_NAME = %s",
            DB_NAME,
            $tableName,
            $indexName
        ));
    }

    private function findCheckConstraint(
        string $tableName,
        string ...$requiredFragments
    ): ?string {
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT c.CONSTRAINT_NAME AS constraint_name, "
            . "c.CHECK_CLAUSE AS check_clause "
            . "FROM information_schema.CHECK_CONSTRAINTS c "
            . "INNER JOIN information_schema.TABLE_CONSTRAINTS t "
            . "ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA "
            . "AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME "
            . "WHERE t.CONSTRAINT_SCHEMA = %s AND t.TABLE_NAME = %s "
            . "AND t.CONSTRAINT_TYPE = 'CHECK'",
            DB_NAME,
            $tableName
        ), ARRAY_A);

        foreach ($rows as $row) {
            $matches = true;

            foreach ($requiredFragments as $fragment) {
                if (!str_contains($row["check_clause"], $fragment)) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return $row["constraint_name"];
            }
        }

        return null;
    }
}
