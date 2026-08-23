<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Catalog\Classification\ClassificationSeedEvolution;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbClassificationSeedEvolutionFactory;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Library\LibraryRepository;
use RuntimeException;
use wpdb;

final readonly class CoreSchema1002Migration implements CoreSchemaMigration
{
    private LibraryRepository $libraries;
    private ClassificationSeedEvolution $seedEvolution;
    private TransactionManager $transactionManager;
    private CoreSchemaHealthChecker $healthChecker;

    public function __construct(
        wpdb $database,
        CoreTableNames $tableNames,
        ?LibraryRepository $libraries = null,
        ?ClassificationSeedEvolution $seedEvolution = null,
        ?TransactionManager $transactionManager = null
    ) {
        $this->libraries = $libraries
            ?? new WpdbLibraryRepository($database, $tableNames, false);
        $this->seedEvolution = $seedEvolution
            ?? WpdbClassificationSeedEvolutionFactory::create(
                $database,
                $tableNames
            );
        $this->transactionManager = $transactionManager
            ?? new WpdbTransactionManager($database);
        $this->healthChecker = new CoreSchemaHealthChecker(
            $database,
            $tableNames,
            $this->seedEvolution
        );
    }

    public function sourceVersion(): int
    {
        return 1001;
    }

    public function targetVersion(): int
    {
        return 1002;
    }

    public function assertPrecondition(): void
    {
        $health = $this->healthChecker->inspectForVersion(1001);

        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    public function migrate(): void
    {
        foreach ($this->libraries->all() as $library) {
            $this->transactionManager->run(function () use ($library): void {
                $this->seedEvolution->evolve($library->id());
            });
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->healthChecker->inspectForVersion(1002);

        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }

        foreach ($this->libraries->all() as $library) {
            if (!$this->seedEvolution->isConverged($library->id())) {
                throw new RuntimeException(
                    "Classification seed evolution is incomplete for Library "
                    . $library->id()->value() . "."
                );
            }
        }
    }
}
