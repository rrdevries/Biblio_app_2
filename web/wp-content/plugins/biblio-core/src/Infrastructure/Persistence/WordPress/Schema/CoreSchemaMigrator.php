<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Throwable;
use wpdb;

final readonly class CoreSchemaMigrator
{
    /**
     * Versions 1-5 belonged to the pre-baseline Fase-0 spike. The formally
     * supported production schema history starts at 1000.
     */
    public const FORMAL_BASELINE_VERSION = 1000;
    public const CURRENT_VERSION = 1011;
    public const VERSION_OPTION = "biblio_core_schema_version";
    public const LEGACY_VERSION_OPTION = "biblio_core_library_schema_version";

    private CoreSchemaBaselineInstaller $baselineInstaller;
    private CoreSchemaHealthChecker $healthChecker;

    /** @var list<CoreSchemaMigration> */
    private array $migrations;

    /** @param list<CoreSchemaMigration> $migrations */
    public function __construct(
        private wpdb $database,
        CoreTableNames $tableNames,
        array $migrations = []
    ) {
        $this->baselineInstaller = new CoreSchemaBaselineInstaller(
            $database,
            $tableNames
        );
        $this->healthChecker = new CoreSchemaHealthChecker(
            $database,
            $tableNames
        );
        $this->migrations = $this->orderAndValidateMigrations($migrations);
    }

    public function migrate(): void
    {
        $installedVersion = $this->installedVersion();

        if ($installedVersion === null) {
            $this->assertNoLegacySpikeVersion();
            $this->baselineInstaller->install();
            $this->assertHealthyForVersion(self::FORMAL_BASELINE_VERSION);
            $this->recordVersion(self::FORMAL_BASELINE_VERSION);
            $installedVersion = self::FORMAL_BASELINE_VERSION;
        }

        if ($installedVersion < self::FORMAL_BASELINE_VERSION) {
            throw new CoreSchemaMigrationException(
                "Unsupported pre-baseline Biblio Core schema version "
                . $installedVersion . ". Rebuild this development schema "
                . "from the formal baseline."
            );
        }

        if ($installedVersion > $this->expectedVersion()) {
            throw new CoreSchemaMigrationException(
                "Installed Biblio Core schema version {$installedVersion} "
                . "is newer than supported version "
                . $this->expectedVersion() . "."
            );
        }

        while ($installedVersion < $this->expectedVersion()) {
            $migration = $this->migrationFrom($installedVersion);

            if ($migration === null) {
                throw new CoreSchemaMigrationException(
                    "No explicit Biblio Core migration exists from schema "
                    . "version {$installedVersion}."
                );
            }

            try {
                $migration->assertPrecondition();
                $migration->migrate();
                $migration->assertPostcondition();
            } catch (Throwable $exception) {
                throw new CoreSchemaMigrationException(
                    "Biblio Core migration {$migration->sourceVersion()} -> "
                    . "{$migration->targetVersion()} failed before the "
                    . "version bump: {$exception->getMessage()}",
                    0,
                    $exception
                );
            }

            $this->recordVersion($migration->targetVersion());
            $installedVersion = $migration->targetVersion();
        }

        $this->assertHealthyForVersion(min(
            $this->expectedVersion(),
            self::CURRENT_VERSION
        ));
    }

    public function installedVersion(): ?int
    {
        $value = get_option(self::VERSION_OPTION, null);

        if ($value === null) {
            return null;
        }

        if (
            (!is_int($value) && !is_string($value))
            || preg_match('/^[1-9][0-9]*$/', (string) $value) !== 1
        ) {
            throw new CoreSchemaMigrationException(
                "Installed Biblio Core schema version is invalid: "
                . var_export($value, true)
            );
        }

        return (int) $value;
    }

    public function expectedVersion(): int
    {
        if ($this->migrations === []) {
            return self::CURRENT_VERSION;
        }

        return $this->migrations[array_key_last($this->migrations)]
            ->targetVersion();
    }

    public function health(): CoreSchemaHealth
    {
        return $this->healthForVersion(self::CURRENT_VERSION);
    }

    public function healthForVersion(int $expectedVersion): CoreSchemaHealth
    {
        return $this->healthChecker->inspectForVersion($expectedVersion);
    }

    private function assertHealthyForVersion(int $expectedVersion): void
    {
        $health = $this->healthForVersion($expectedVersion);

        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    private function assertNoLegacySpikeVersion(): void
    {
        $legacyVersion = get_option(self::LEGACY_VERSION_OPTION, null);

        if ($legacyVersion === null) {
            return;
        }

        throw new CoreSchemaMigrationException(
            "Legacy Fase-0 spike schema version "
            . var_export($legacyVersion, true)
            . " is not a supported production migration source. Rebuild "
            . "this development schema from formal baseline version "
            . self::FORMAL_BASELINE_VERSION . "."
        );
    }

    private function migrationFrom(int $sourceVersion): ?CoreSchemaMigration
    {
        foreach ($this->migrations as $migration) {
            if ($migration->sourceVersion() === $sourceVersion) {
                return $migration;
            }
        }

        return null;
    }

    private function recordVersion(int $version): void
    {
        update_option(self::VERSION_OPTION, (string) $version, false);
        $recorded = get_option(self::VERSION_OPTION, null);

        if ((string) $recorded !== (string) $version) {
            throw new CoreSchemaMigrationException(
                "Could not record Biblio Core schema version {$version}."
            );
        }
    }

    /**
     * @param list<CoreSchemaMigration> $migrations
     * @return list<CoreSchemaMigration>
     */
    private function orderAndValidateMigrations(array $migrations): array
    {
        usort(
            $migrations,
            static fn (
                CoreSchemaMigration $left,
                CoreSchemaMigration $right
            ): int => $left->sourceVersion() <=> $right->sourceVersion()
        );
        $expectedSource = self::FORMAL_BASELINE_VERSION;

        foreach ($migrations as $migration) {
            if ($migration->sourceVersion() !== $expectedSource) {
                throw new CoreSchemaMigrationException(
                    "Migration chain must continue from schema version "
                    . "{$expectedSource}; found source version "
                    . $migration->sourceVersion() . "."
                );
            }

            if ($migration->targetVersion() <= $migration->sourceVersion()) {
                throw new CoreSchemaMigrationException(
                    "Migration target version must be greater than its "
                    . "source version."
                );
            }

            $expectedSource = $migration->targetVersion();
        }

        return $migrations;
    }
}
