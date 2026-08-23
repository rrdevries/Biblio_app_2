<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Catalog\AddLibraryItemService;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextActivity;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitializer;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogSelectionResolver;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Audit\ActivityEventAppender;
use Biblio\Core\Audit\ActivityEventFactory;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\Classification\LibraryBookTypeRepository;
use Biblio\Core\Catalog\Classification\LibraryGenreRepository;
use Biblio\Core\Catalog\Classification\LibrarySubjectRepository;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryCatalogContextRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationException;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaBaselineInstaller;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchema1001Migration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationRegistry;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMutationLock;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use RuntimeException;

final class Schema1001MigrationTest extends PersistenceIntegrationTestCase
{
    public function testVersion1000DataAndManagerPermissionsMigrateWithoutLoss(): void
    {
        $this->downgradeToVersion1000();
        $this->insertVersion1000Fixture();
        $baselineBefore = $this->baselineSchemaSnapshot();

        try {
            $this->migrator()->migrate();

            self::assertSame(1001, $this->migrator()->installedVersion());
            self::assertTrue(
                $this->migrator()->healthForVersion(1001)->isHealthy()
            );
            self::assertSame($baselineBefore, $this->baselineSchemaSnapshot());
            self::assertSame("Migration Work", $this->database->get_var(
                "SELECT work_title FROM `{$this->tableNames->works()}` "
                . "WHERE work_id = 'migration-work'"
            ));
            self::assertSame(1, (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tableNames->readingRounds()}` "
                . "WHERE reading_round_id = 'migration-round'"
            ));

            self::assertSame(
                '["unknown.one","  spaced.permission  ","catalog.item_add"]',
                $this->permissionsFor(101)
            );
            self::assertSame(
                '[ "catalog.item_add" , "unknown.two" ]',
                $this->permissionsFor(102)
            );
            self::assertSame(
                '["owner.permission"]',
                $this->permissionsFor(103)
            );
            self::assertSame(
                '["member.permission"]',
                $this->permissionsFor(104)
            );
            self::assertStringNotContainsString(
                "catalog.classification_manage",
                $this->permissionsFor(101)
            );
            $managerId = new UserId("101");
            $libraryId = new LibraryId("migration-library");
            $access = new LibraryAccessService(
                new WpdbLibraryMembershipRepository(
                    $this->database,
                    $this->tableNames
                ),
                new LibraryAuthorizationPolicy()
            );
            $context = new LibraryContext($libraryId, $managerId);
            self::assertTrue($access->canAddCatalogItem($context));
            self::assertTrue(
                $access->canInitializeCatalogContextDuringItemAdd($context)
            );
            self::assertFalse(
                $access->canModifyLibraryCatalogContext($context)
            );
            self::assertFalse(
                $access->canManageClassificationTerms($context)
            );
            $this->database->insert(
                $this->tableNames->libraryBookTypes(),
                [
                    "library_id" => "migration-library",
                    "book_type_id" => "migration-book-type",
                    "display_name" => "Migration Book Type",
                    "normalized_name" => "migration book type",
                    "term_status" => "inactive",
                    "seed_key" => null,
                ]
            );
            $this->database->insert(
                $this->tableNames->libraryCatalogContexts(),
                [
                    "library_id" => "migration-library",
                    "work_id" => "migration-work",
                    "book_type_id" => "migration-book-type",
                    "context_version" => 1,
                ]
            );
            $catalogContexts = new WpdbLibraryCatalogContextRepository(
                $this->database,
                $this->tableNames
            );
            $item = (new AddLibraryItemService(
                new ControllableAuthenticatedUser($managerId),
                $access,
                new WpdbWorkRepository($this->database, $this->tableNames),
                new WpdbEditionRepository($this->database, $this->tableNames),
                new WpdbItemRepository($this->database, $this->tableNames),
                $catalogContexts,
                new LibraryCatalogContextInitializer(
                    $catalogContexts,
                    new LibraryCatalogSelectionResolver(
                        $this->createStub(LibraryBookTypeRepository::class),
                        $this->createStub(LibraryGenreRepository::class),
                        $this->createStub(LibrarySubjectRepository::class)
                    ),
                    $this->createStub(LibraryMutationLock::class)
                ),
                new LibraryCatalogContextActivity(
                    $this->createStub(ActivityEventFactory::class)
                ),
                $this->createStub(ActivityEventAppender::class),
                new WpdbTransactionManager($this->database)
            ))->addForExistingEdition(
                $libraryId,
                new ItemId("migration-manager-item"),
                new EditionId("migration-edition")
            );
            self::assertSame("migration-library", $item->libraryId()->value());

            $permissionsAfterFirstRun = $this->permissionsFor(101);
            $this->migrator()->migrate();
            self::assertSame(
                $permissionsAfterFirstRun,
                $this->permissionsFor(101)
            );
            self::assertSame(
                1,
                substr_count(
                    $this->permissionsFor(101),
                    "catalog.item_add"
                )
            );
        } finally {
            $this->restoreCurrentSchema();
        }
    }

    public function testMalformedPermissionPayloadFailsBeforeDdlAndVersionBump(): void
    {
        $this->downgradeToVersion1000();
        $this->insertLibrary("permission-library");
        $this->insertMembership(
            "permission-library",
            201,
            "manager",
            "active",
            '{"not":"a-list"}'
        );
        try {
            $this->migrator()->migrate();
            self::fail("Malformed permission data was silently repaired.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString(
                "permissions are invalid",
                $exception->getMessage()
            );
            self::assertSame(1000, $this->migrator()->installedVersion());
            self::assertSame(0, $this->existingSchema1001AdditionCount());
            self::assertSame(
                '{"not":"a-list"}',
                $this->permissionsFor(201)
            );
        } finally {
            $this->database->update(
                $this->tableNames->memberships(),
                ["additional_permissions" => "[]"],
                ["user_id" => 201],
                ["%s"],
                ["%d"]
            );
            $this->restoreCurrentSchema();
        }
    }

    public function testEveryReachableCorrectPartialDdlStateIsRetryable(): void
    {
        $additions = $this->tableNames->schema1001Additions();
        $this->downgradeToVersion1000();

        try {
            for ($retained = 0; $retained <= count($additions); $retained++) {
                $this->dropSchema1001Additions();
                update_option(CoreSchemaMigrator::VERSION_OPTION, "1000", false);
                $this->migrator()->migrate();

                foreach (array_reverse(array_slice($additions, $retained)) as $table) {
                    $this->database->query("DROP TABLE `{$table}`");
                }
                update_option(CoreSchemaMigrator::VERSION_OPTION, "1000", false);

                $this->migrator()->migrate();

                self::assertSame(1001, $this->migrator()->installedVersion());
                self::assertSame(7, $this->existingSchema1001AdditionCount());
                self::assertTrue(
                    $this->migrator()->healthForVersion(1001)->isHealthy(),
                    "Retry failed with {$retained} retained schema-1001 tables."
                );
            }
        } finally {
            $this->restoreCurrentSchema();
        }
    }

    public function testUnknownPartialTableDriftFailsClosedWithoutVersionBump(): void
    {
        $this->downgradeToVersion1000();
        $this->migrator()->migrate();
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1000", false);
        $table = $this->tableNames->libraryGenres();
        $this->database->query(
            "ALTER TABLE `{$table}` DROP INDEX `genres_by_normalized_name`"
        );

        try {
            $this->migrator()->migrate();
            self::fail("Unknown schema-1001 drift was repaired automatically.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString(
                "missing required index genres_by_normalized_name",
                $exception->getMessage()
            );
            self::assertSame(1000, $this->migrator()->installedVersion());
        } finally {
            $this->database->query(
                "ALTER TABLE `{$table}` ADD UNIQUE KEY "
                . "`genres_by_normalized_name` (library_id, normalized_name)"
            );
            $this->restoreCurrentSchema();
        }
    }

    private function insertVersion1000Fixture(): void
    {
        $this->insertLibrary("migration-library");
        $this->insertMembership(
            "migration-library",
            101,
            "manager",
            "active",
            '["unknown.one","  spaced.permission  "]'
        );
        $this->insertMembership(
            "migration-library",
            102,
            "manager",
            "inactive",
            '[ "catalog.item_add" , "unknown.two" ]'
        );
        $this->insertMembership(
            "migration-library",
            103,
            "owner",
            "active",
            '["owner.permission"]'
        );
        $this->insertMembership(
            "migration-library",
            104,
            "member",
            "active",
            '["member.permission"]'
        );
        $this->database->insert($this->tableNames->works(), [
            "work_id" => "migration-work",
            "work_title" => "Migration Work",
        ]);
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => "migration-edition",
            "work_id" => "migration-work",
        ]);
        $this->database->insert($this->tableNames->items(), [
            "item_id" => "migration-item",
            "library_id" => "migration-library",
            "edition_id" => "migration-edition",
            "item_status" => "active",
        ]);
        $this->database->insert($this->tableNames->readingRounds(), [
            "reading_round_id" => "migration-round",
            "user_id" => 101,
            "work_id" => "migration-work",
            "item_id" => "migration-item",
            "external_loan_id" => null,
            "round_status" => "active",
            "started_at" => "2026-08-20 12:00:00.000000",
        ]);
    }

    private function insertLibrary(string $libraryId): void
    {
        $this->database->insert($this->tableNames->libraries(), [
            "library_id" => $libraryId,
            "library_type" => "private_library",
            "library_status" => "active",
        ]);
    }

    private function insertMembership(
        string $libraryId,
        int $userId,
        string $role,
        string $status,
        string $permissions
    ): void {
        $this->database->insert($this->tableNames->memberships(), [
            "library_id" => $libraryId,
            "user_id" => $userId,
            "membership_status" => $status,
            "management_role" => $role,
            "use_access" => "direct",
            "additional_permissions" => $permissions,
        ]);
    }

    private function permissionsFor(int $userId): string
    {
        return (string) $this->database->get_var($this->database->prepare(
            "SELECT additional_permissions FROM "
            . "`{$this->tableNames->memberships()}` WHERE user_id = %d",
            $userId
        ));
    }

    /** @return array<string, string> */
    private function baselineSchemaSnapshot(): array
    {
        $snapshot = [];

        foreach ($this->tableNames->all() as $table) {
            $row = $this->database->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);

            if (!is_array($row) || !isset($row[1])) {
                throw new RuntimeException("Could not inspect {$table}.");
            }

            $snapshot[$table] = (string) $row[1];
        }

        return $snapshot;
    }

    private function downgradeToVersion1000(): void
    {
        foreach (array_reverse($this->tableNames->schema1006()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        }
        delete_option(CoreSchemaMigrator::VERSION_OPTION);
        (new CoreSchemaBaselineInstaller(
            $this->database,
            $this->tableNames
        ))->install();
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1000", false);
    }

    private function dropSchema1001Additions(): void
    {
        foreach (
            array_reverse($this->tableNames->schema1001Additions())
            as $table
        ) {
            $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    private function restoreCurrentSchema(): void
    {
        if ($this->productionMigrator()->installedVersion() !== 1007) {
            $this->productionMigrator()->migrate();
        }
    }

    private function existingSchema1001AdditionCount(): int
    {
        $count = 0;

        foreach ($this->tableNames->schema1001Additions() as $table) {
            $count += (int) $this->database->get_var($this->database->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES "
                . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $table
            ));
        }

        return $count;
    }

    private function migrator(): CoreSchemaMigrator
    {
        return new CoreSchemaMigrator(
            $this->database,
            $this->tableNames,
            [new CoreSchema1001Migration($this->database, $this->tableNames)]
        );
    }

    private function productionMigrator(): CoreSchemaMigrator
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
}
