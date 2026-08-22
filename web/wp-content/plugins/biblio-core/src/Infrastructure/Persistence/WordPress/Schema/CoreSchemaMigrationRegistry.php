<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchemaMigrationRegistry
{
    /** @var list<CoreSchemaMigration> */
    private array $migrations;

    /** @param list<CoreSchemaMigration> $migrations */
    private function __construct(array $migrations)
    {
        $this->migrations = $migrations;
    }

    public static function production(
        wpdb $database,
        CoreTableNames $tableNames
    ): self {
        $registry = new self(self::productionMigrations(
            $database,
            $tableNames
        ));
        $registry->assertProductionChainTargetsCurrentVersion();

        return $registry;
    }

    public static function explicit(CoreSchemaMigration ...$migrations): self
    {
        return new self($migrations);
    }

    /** @return list<CoreSchemaMigration> */
    public function migrations(): array
    {
        return $this->migrations;
    }

    /** @return list<CoreSchemaMigration> */
    private static function productionMigrations(
        wpdb $database,
        CoreTableNames $tableNames
    ): array {
        return [
            new CoreSchema1001Migration($database, $tableNames),
            new CoreSchema1002Migration($database, $tableNames),
            new CoreSchema1003Migration($database, $tableNames),
        ];
    }

    private function assertProductionChainTargetsCurrentVersion(): void
    {
        $expectedSource = CoreSchemaMigrator::FORMAL_BASELINE_VERSION;

        foreach ($this->migrations as $migration) {
            if (
                $migration->sourceVersion() !== $expectedSource
                || $migration->targetVersion() <= $expectedSource
            ) {
                throw new CoreSchemaMigrationException(
                    "Production migration registry must be a contiguous "
                    . "forward chain from schema version {$expectedSource}."
                );
            }

            $expectedSource = $migration->targetVersion();
        }

        if ($expectedSource !== CoreSchemaMigrator::CURRENT_VERSION) {
            throw new CoreSchemaMigrationException(
                "Production migration registry ends at schema version "
                . "{$expectedSource}; current schema version is "
                . CoreSchemaMigrator::CURRENT_VERSION . "."
            );
        }
    }
}
