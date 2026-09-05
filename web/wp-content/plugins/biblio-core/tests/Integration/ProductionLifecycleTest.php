<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Catalog\Classification\Read\LibraryClassificationQueryService;
use Biblio\Core\Application\Catalog\Query\CatalogQueryService;
use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Library\EnsurePersonalPrivateLibraryService;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Application\Reading\GetOwnedReadingRoundService;
use Biblio\Core\Application\Reading\StartReadingFromExternalLoanService;
use Biblio\Core\Application\Reading\StartReadingFromLibraryItemService;
use Biblio\Core\Exception\CoreFailure;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationException;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationRegistry;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;
use Biblio\Core\Infrastructure\WordPress\Lifecycle\CoreLifecycleException;
use Biblio\Core\Infrastructure\WordPress\Lifecycle\WpTransientLifecycleStateStore;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Plugin;
use ReflectionProperty;
use RuntimeException;

final class LifecycleProbeMigration implements CoreSchemaMigration
{
    public const INDEX_NAME = "f1_3_lifecycle_probe";

    private int $attempts = 0;

    public function __construct(
        private readonly \wpdb $database,
        private readonly \Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames $tableNames,
        private bool $failFirstAttempt = false
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
        if (!$this->tableExists()) {
            throw new RuntimeException("Lifecycle probe requires Works.");
        }
    }

    public function migrate(): void
    {
        ++$this->attempts;

        if (!$this->indexExists()) {
            $works = $this->tableNames->works();
            $result = $this->database->query(
                "ALTER TABLE `{$works}` ADD INDEX `" . self::INDEX_NAME
                . "` (work_title)"
            );

            if ($result === false) {
                throw new RuntimeException("Could not create lifecycle probe.");
            }
        }

        if ($this->failFirstAttempt && $this->attempts === 1) {
            throw new RuntimeException("Forced lifecycle migration failure.");
        }
    }

    public function assertPostcondition(): void
    {
        if (!$this->indexExists()) {
            throw new RuntimeException("Lifecycle probe is missing.");
        }
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function remove(): void
    {
        if ($this->indexExists()) {
            $works = $this->tableNames->works();
            $this->database->query(
                "ALTER TABLE `{$works}` DROP INDEX `" . self::INDEX_NAME . "`"
            );
        }
    }

    private function indexExists(): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS "
                . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s "
                . "AND INDEX_NAME = %s",
            DB_NAME,
            $this->tableNames->works(),
            self::INDEX_NAME
        )) === 1;
    }

    private function tableExists(): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES "
                . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $this->tableNames->works()
        )) === 1;
    }
}

final class ProductionLifecycleTest extends PersistenceIntegrationTestCase
{
    private WpTransientLifecycleStateStore $state;

    protected function setUp(): void
    {
        parent::setUp();

        $this->state = new WpTransientLifecycleStateStore(300, 60);
        $this->state->clear();
    }

    protected function tearDown(): void
    {
        $this->state->clear();
        delete_option(CoreSchemaMigrator::LEGACY_VERSION_OPTION);

        parent::tearDown();
    }

    public function testActivationInstallsAndValidatesFreshBaseline(): void
    {
        $this->dropCoreSchema();
        $composition = $this->composition();

        try {
            $composition->lifecycle()->activate();

            $migrator = $this->migrator();
            self::assertSame(32, $this->existingCoreTableCount());
            self::assertSame(1013, $migrator->installedVersion());
            self::assertTrue(
                $migrator->health()->isHealthy(),
                $migrator->health()->summary()
            );
            self::assertTrue($this->state->isHealthCurrent(1013));
        } finally {
            $this->ensureBaseline();
        }
    }

    public function testProductionRegistryMatchesCurrentBaselineVersion(): void
    {
        $registry = CoreSchemaMigrationRegistry::production(
            $this->database,
            $this->tableNames
        );

        self::assertSame(1000, CoreSchemaMigrator::FORMAL_BASELINE_VERSION);
        self::assertSame(1013, CoreSchemaMigrator::CURRENT_VERSION);
        self::assertCount(13, $registry->migrations());
        self::assertSame(1000, $registry->migrations()[0]->sourceVersion());
        self::assertSame(1001, $registry->migrations()[0]->targetVersion());
        self::assertSame(1001, $registry->migrations()[1]->sourceVersion());
        self::assertSame(1002, $registry->migrations()[1]->targetVersion());
        self::assertSame(1002, $registry->migrations()[2]->sourceVersion());
        self::assertSame(1003, $registry->migrations()[2]->targetVersion());
        self::assertSame(1003, $registry->migrations()[3]->sourceVersion());
        self::assertSame(1004, $registry->migrations()[3]->targetVersion());
        self::assertSame(1004, $registry->migrations()[4]->sourceVersion());
        self::assertSame(1005, $registry->migrations()[4]->targetVersion());
        self::assertSame(1005, $registry->migrations()[5]->sourceVersion());
        self::assertSame(1006, $registry->migrations()[5]->targetVersion());
        self::assertSame(1006, $registry->migrations()[6]->sourceVersion());
        self::assertSame(1007, $registry->migrations()[6]->targetVersion());
        self::assertSame(1008, $registry->migrations()[7]->targetVersion());
        self::assertSame(1008, $registry->migrations()[8]->sourceVersion());
        self::assertSame(1009, $registry->migrations()[8]->targetVersion());
        self::assertSame(1009, $registry->migrations()[9]->sourceVersion());
        self::assertSame(1010, $registry->migrations()[9]->targetVersion());
        self::assertSame(1010, $registry->migrations()[10]->sourceVersion());
        self::assertSame(1011, $registry->migrations()[10]->targetVersion());
        self::assertSame(1011, $registry->migrations()[11]->sourceVersion());
        self::assertSame(1012, $registry->migrations()[11]->targetVersion());
        self::assertSame(1012, $registry->migrations()[12]->sourceVersion());
        self::assertSame(1013, $registry->migrations()[12]->targetVersion());
    }

    public function testActivationOfCurrentSchemaIsSchemaAndDataNoOp(): void
    {
        $works = $this->tableNames->works();
        $this->database->insert(
            $works,
            ["work_id" => "lifecycle-sentinel", "work_title" => "Sentinel"],
            ["%s", "%s"]
        );
        $schemaBefore = $this->showCreateTable($works);

        $this->composition()->lifecycle()->activate();

        self::assertSame($schemaBefore, $this->showCreateTable($works));
        self::assertSame(
            "Sentinel",
            $this->database->get_var($this->database->prepare(
                "SELECT work_title FROM `{$works}` WHERE work_id = %s",
                "lifecycle-sentinel"
            ))
        );
    }

    public function testRuntimeRunsOnlyMissingSupportedMigration(): void
    {
        $migration = new LifecycleProbeMigration(
            $this->database,
            $this->tableNames
        );
        $composition = $this->composition([$migration]);

        try {
            $composition->lifecycle()->boot();

            self::assertSame(1, $migration->attempts());
            self::assertSame(1014, $this->migrator()->installedVersion());
            self::assertTrue($this->state->isHealthCurrent(1014));
        } finally {
            $migration->remove();
            update_option(CoreSchemaMigrator::VERSION_OPTION, "1013", false);
        }
    }

    public function testFailedMigrationIsBackedOffWithoutFalseVersionBump(): void
    {
        $works = $this->tableNames->works();
        $this->database->insert(
            $works,
            ["work_id" => "failure-sentinel", "work_title" => "Preserved"],
            ["%s", "%s"]
        );
        $migration = new LifecycleProbeMigration(
            $this->database,
            $this->tableNames,
            true
        );
        $composition = $this->composition([$migration]);

        try {
            try {
                $composition->lifecycle()->boot();
                self::fail("Forced lifecycle migration failure was hidden.");
            } catch (CoreSchemaMigrationException $exception) {
                self::assertSame(
                    FailureReason::SchemaMigrationFailed,
                    $exception->reason()
                );
            }

            self::assertSame(1, $migration->attempts());
            self::assertSame(1013, $this->migrator()->installedVersion());
            self::assertSame(
                "Preserved",
                $this->database->get_var($this->database->prepare(
                    "SELECT work_title FROM `{$works}` WHERE work_id = %s",
                    "failure-sentinel"
                ))
            );

            try {
                $composition->lifecycle()->boot();
                self::fail("Lifecycle failure backoff was not enforced.");
            } catch (CoreLifecycleException $exception) {
                self::assertTrue($exception->isCached());
                self::assertSame(
                    FailureReason::SchemaMigrationFailed,
                    $exception->reason()
                );
            }

            self::assertSame(1, $migration->attempts());

            $this->state->clear();
            $composition->lifecycle()->boot();
            self::assertSame(2, $migration->attempts());
            self::assertSame(1014, $this->migrator()->installedVersion());
        } finally {
            $migration->remove();
            update_option(CoreSchemaMigrator::VERSION_OPTION, "1013", false);
        }
    }

    public function testCurrentDriftBlocksApplicationWithoutRepair(): void
    {
        $table = $this->tableNames->readingRounds();
        $index = "one_active_external_round_per_user";
        $this->database->query(
            "ALTER TABLE `{$table}` DROP INDEX `{$index}`"
        );
        $composition = $this->composition();
        $plugin = new Plugin(
            dirname(__DIR__, 2) . "/biblio-core.php",
            static fn (): ProductionComposition => $composition
        );

        try {
            $plugin->initialize();

            self::assertNull($plugin->application());
            $failure = $plugin->bootFailure();
            self::assertInstanceOf(CoreFailure::class, $failure);
            self::assertSame(
                FailureReason::SchemaHealthFailed,
                $failure->reason()
            );
            self::assertSame(0, $this->indexCount($table, $index));

            $previousUser = get_current_user_id();
            wp_set_current_user(1);
            ob_start();
            $plugin->renderAdminNotice();
            $notice = (string) ob_get_clean();
            wp_set_current_user($previousUser);

            self::assertStringContainsString(
                "schema_health_failed",
                $notice
            );
            self::assertStringContainsString(
                "Biblio Core is niet operationeel",
                $notice
            );
            self::assertStringNotContainsString(
                "missing required index",
                $notice
            );
        } finally {
            $this->database->query(
                "ALTER TABLE `{$table}` ADD UNIQUE KEY `{$index}` "
                    . "(active_external_loan_user_id, external_loan_id)"
            );
        }
    }

    public function testLegacyVersionFailsActivationWithoutAdoption(): void
    {
        $this->dropCoreSchema();
        update_option(CoreSchemaMigrator::LEGACY_VERSION_OPTION, "5", false);
        $composition = $this->composition();
        $plugin = new Plugin(
            dirname(__DIR__, 2) . "/biblio-core.php",
            static fn (): ProductionComposition => $composition
        );

        try {
            $plugin->activate();
            self::fail("Legacy schema was silently adopted.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertSame(
                FailureReason::SchemaMigrationFailed,
                $exception->reason()
            );
            self::assertSame($exception, $plugin->bootFailure());
            self::assertNull($this->migrator()->installedVersion());
            self::assertSame(0, $this->existingCoreTableCount());
        } finally {
            delete_option(CoreSchemaMigrator::LEGACY_VERSION_OPTION);
            $this->ensureBaseline();
        }
    }

    public function testCompositionExposesOnlySharedApplicationServices(): void
    {
        $composition = $this->composition();
        $application = $composition->application();

        self::assertSame($application, $composition->application());
        self::assertInstanceOf(CoreApplication::class, $application);
        self::assertInstanceOf(
            EnsurePersonalPrivateLibraryService::class,
            $application->personalLibraries()
        );
        self::assertInstanceOf(
            GetAccessibleLibraryItemService::class,
            $application->accessibleLibraryItems()
        );
        self::assertInstanceOf(
            GetOwnedExternalLoanService::class,
            $application->ownedExternalLoans()
        );
        self::assertInstanceOf(
            GetOwnedReadingRoundService::class,
            $application->ownedReadingRounds()
        );
        self::assertInstanceOf(
            LibraryClassificationQueryService::class,
            $application->libraryClassifications()
        );
        self::assertInstanceOf(CatalogQueryService::class, $application->catalogQuery());
        self::assertInstanceOf(
            StartReadingFromLibraryItemService::class,
            $application->libraryItemReading()
        );
        self::assertInstanceOf(
            StartReadingFromExternalLoanService::class,
            $application->externalLoanReading()
        );
        self::assertSame(
            $this->privateProperty(
                $application->libraryItemReading(),
                "createReadingRound"
            ),
            $this->privateProperty(
                $application->externalLoanReading(),
                "createReadingRound"
            )
        );
        self::assertSame(
            $this->privateProperty(
                $this->privateProperty(
                    $application->personalLibraries(),
                    "createLibraryService"
                ),
                "membershipRepository"
            ),
            $this->privateProperty(
                $this->privateProperty(
                    $application->accessibleLibraryItems(),
                    "libraryAccessService"
                ),
                "repository"
            )
        );
        self::assertSame(
            $application->ownedExternalLoans(),
            $this->privateProperty(
                $application->externalLoanReading(),
                "getOwnedExternalLoan"
            )
        );
        self::assertFalse(method_exists(
            $this->privateProperty(
                $application->ownedExternalLoans(),
                "externalLoanRepository"
            ),
            "add"
        ));
        self::assertSame(
            $this->privateProperty(
                $application->ownedReadingRounds(),
                "readingRoundRepository"
            ),
            $this->privateProperty(
                $this->privateProperty(
                    $this->privateProperty(
                        $application->libraryItemReading(),
                        "createReadingRound"
                    ),
                    "creation"
                ),
                "rounds"
            )
        );
        $authenticatedUser = $this->privateProperty(
            $application->personalLibraries(),
            "authenticatedUser"
        );
        self::assertSame(
            $authenticatedUser,
            $this->privateProperty(
                $application->accessibleLibraryItems(),
                "authenticatedUser"
            )
        );
        self::assertSame(
            $authenticatedUser,
            $this->privateProperty(
                $application->ownedExternalLoans(),
                "authenticatedUser"
            )
        );
        self::assertSame(
            $authenticatedUser,
            $this->privateProperty(
                $application->ownedReadingRounds(),
                "authenticatedUser"
            )
        );
        self::assertSame(
            $authenticatedUser,
            $this->privateProperty(
                $this->privateProperty(
                    $application->libraryItemReading(),
                    "createReadingRound"
                ),
                "authenticatedUser"
            )
        );

        foreach (get_class_methods($composition) as $method) {
            self::assertStringNotContainsString("repository", strtolower($method));
        }
    }

    public function testPluginBootAndInitializationAreIdempotent(): void
    {
        $composition = $this->composition();
        $pluginFile = dirname(__DIR__, 2) . "/biblio-core.php";
        $plugin = new Plugin(
            $pluginFile,
            static fn (): ProductionComposition => $composition
        );
        $initializations = 0;
        $publishedApplication = null;
        $listener = static function (CoreApplication $application) use (
            &$initializations,
            &$publishedApplication
        ): void {
            ++$initializations;
            $publishedApplication = $application;
        };
        add_action("biblio_core_initialized", $listener);

        try {
            $plugin->boot();
            $plugin->boot();
            $plugin->initialize();
            $plugin->initialize();

            self::assertSame(1, has_action("init", [$plugin, "initialize"]));
            self::assertSame(
                10,
                has_action(
                    "activate_" . plugin_basename($pluginFile),
                    [$plugin, "activate"]
                )
            );
            self::assertSame(1, $initializations);
            self::assertSame($composition->application(), $publishedApplication);
            self::assertSame($publishedApplication, $plugin->application());
            self::assertNull($plugin->bootFailure());
        } finally {
            remove_action("biblio_core_initialized", $listener);
            remove_action("init", [$plugin, "initialize"], 1);
            remove_action("admin_notices", [$plugin, "renderAdminNotice"]);
            remove_action(
                "activate_" . plugin_basename($pluginFile),
                [$plugin, "activate"]
            );
        }
    }

    /** @param list<CoreSchemaMigration> $migrations */
    private function composition(array $migrations = []): ProductionComposition
    {
        $registry = null;

        if ($migrations !== []) {
            $productionMigrations = CoreSchemaMigrationRegistry::production(
                $this->database,
                $this->tableNames
            )->migrations();
            $registry = CoreSchemaMigrationRegistry::explicit(
                ...$productionMigrations,
                ...$migrations
            );
        }

        return new ProductionComposition(
            $this->database,
            $this->state,
            $registry
        );
    }

    private function migrator(): CoreSchemaMigrator
    {
        return new CoreSchemaMigrator(
            $this->database,
            $this->tableNames,
            CoreSchemaMigrationRegistry::production(
                $this->database,
                $this->tableNames
            )->migrations()
        );
    }

    private function ensureBaseline(): void
    {
        delete_option(CoreSchemaMigrator::LEGACY_VERSION_OPTION);

        if ($this->existingCoreTableCount() !== 30) {
            $this->dropCoreSchema();
            $this->migrator()->migrate();
        }
    }

    private function dropCoreSchema(): void
    {
        foreach (array_reverse($this->tableNames->schema1013()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        }

        delete_option(CoreSchemaMigrator::VERSION_OPTION);
        delete_option(CoreSchemaMigrator::LEGACY_VERSION_OPTION);
        $this->state->clear();
    }

    private function existingCoreTableCount(): int
    {
        $count = 0;

        foreach ($this->tableNames->schema1013() as $table) {
            $count += (int) $this->database->get_var($this->database->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES "
                    . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $table
            ));
        }

        return $count;
    }

    private function showCreateTable(string $table): string
    {
        $row = $this->database->get_row(
            "SHOW CREATE TABLE `{$table}`",
            ARRAY_N
        );

        if (!is_array($row) || !isset($row[1])) {
            throw new RuntimeException("Could not inspect {$table}.");
        }

        return (string) $row[1];
    }

    private function indexCount(string $table, string $index): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS "
                . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s "
                . "AND INDEX_NAME = %s",
            DB_NAME,
            $table,
            $index
        ));
    }

    private function privateProperty(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }
}
