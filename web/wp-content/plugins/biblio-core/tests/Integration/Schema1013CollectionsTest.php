<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\{CoreSchema1013Migration,CoreSchemaMigrationException,CoreSchemaMigrationRegistry,CoreSchemaMigrator};

final class Schema1013CollectionsTest extends PersistenceIntegrationTestCase
{
    public function testSchema1013IsHealthyTenantConstrainedAndIndexed(): void
    {
        $health = $this->migrator()->healthForVersion(1013);
        self::assertTrue($health->isHealthy(), $health->summary());
        self::assertSame(['library_id', 'collection_id'], $this->indexColumns($this->tableNames->collections(), 'PRIMARY'));
        self::assertSame(['library_id', 'active_normalized_name'], $this->indexColumns($this->tableNames->collections(), 'collections_active_name'));
        self::assertSame(['library_id', 'collection_id', 'active_item_id'], $this->indexColumns($this->tableNames->collectionMemberships(), 'collection_one_active_item'));
        self::assertSame(['library_id', 'collection_id', 'membership_status', 'item_position'], $this->indexColumns($this->tableNames->collectionMemberships(), 'collection_memberships_active_order'));
        self::assertSame(['library_id', 'item_id', 'membership_status'], $this->indexColumns($this->tableNames->collectionMemberships(), 'collection_memberships_by_item'));
        self::assertSame(1, $this->foreignKeyCount($this->tableNames->collections()));
        self::assertSame(2, $this->foreignKeyCount($this->tableNames->collectionMemberships()));
    }

    public function testUpgradeFrom1012PreservesExistingItemsAndStartsWithEmptyCollectionState(): void
    {
        $this->restoreSchema1012();
        $this->database->insert($this->tableNames->works(), ['work_id' => 'work-a', 'work_title' => 'Preserved']);
        $this->database->insert($this->tableNames->editions(), ['edition_id' => 'edition-a', 'work_id' => 'work-a', 'explicitly_no_isbn' => 0]);
        $this->database->insert($this->tableNames->libraries(), ['library_id' => 'library-a', 'library_name' => 'Library', 'library_type' => 'private_library', 'library_status' => 'active']);
        $this->database->insert($this->tableNames->items(), ['item_id' => 'item-a', 'library_id' => 'library-a', 'edition_id' => 'edition-a', 'item_status' => 'active', 'item_version' => 1]);

        $this->migrator()->migrate();

        self::assertSame(1013, $this->migrator()->installedVersion());
        self::assertSame('active', $this->database->get_var("SELECT item_status FROM `{$this->tableNames->items()}` WHERE item_id='item-a'"));
        self::assertSame(0, (int) $this->database->get_var("SELECT COUNT(*) FROM `{$this->tableNames->collections()}`"));
        self::assertSame(0, (int) $this->database->get_var("SELECT COUNT(*) FROM `{$this->tableNames->collectionMemberships()}`"));
    }

    public function testCompletedAndKnownPartialMigrationAreRetrySafe(): void
    {
        $this->restoreSchema1012();
        $migration = new CoreSchema1013Migration($this->database, $this->tableNames);
        $migration->assertPrecondition();
        $migration->migrate();
        $this->database->query("DROP TABLE `{$this->tableNames->collectionMemberships()}`");
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->migrate();
        $migration->assertPostcondition();
        update_option(CoreSchemaMigrator::VERSION_OPTION, '1013', false);

        self::assertTrue($this->migrator()->health()->isHealthy());
    }

    public function testUnknownPartialStateFailsBeforeVersionBumpAndCanRecoverExplicitly(): void
    {
        $this->restoreSchema1012();
        $collections = $this->tableNames->collections();
        $this->database->query("CREATE TABLE `{$collections}` (library_id VARCHAR(191) NOT NULL, collection_id VARCHAR(191) NOT NULL, PRIMARY KEY (library_id,collection_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try {
            $this->migrator()->migrate();
            self::fail('Unknown partial Collection schema was accepted.');
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString('failed before the version bump', $exception->getMessage());
            self::assertStringContainsString('unknown Collection state', $exception->getMessage());
            self::assertSame(1012, $this->migrator()->installedVersion());
        } finally {
            $this->database->query("DROP TABLE IF EXISTS `{$this->tableNames->collectionMemberships()}`");
            $this->database->query("DROP TABLE IF EXISTS `{$collections}`");
            $this->migrator()->migrate();
        }
        self::assertSame(1013, $this->migrator()->installedVersion());
    }

    private function restoreSchema1012(): void
    {
        $this->database->query("DROP TABLE IF EXISTS `{$this->tableNames->collectionMemberships()}`");
        $this->database->query("DROP TABLE IF EXISTS `{$this->tableNames->collections()}`");
        update_option(CoreSchemaMigrator::VERSION_OPTION, '1012', false);
    }

    private function migrator(): CoreSchemaMigrator
    {
        return new CoreSchemaMigrator($this->database, $this->tableNames, CoreSchemaMigrationRegistry::production($this->database, $this->tableNames)->migrations());
    }

    /** @return list<string> */
    private function indexColumns(string $table, string $index): array
    {
        return array_map('strval', $this->database->get_col($this->database->prepare("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s ORDER BY SEQ_IN_INDEX", DB_NAME, $table, $index)));
    }

    private function foreignKeyCount(string $table): int
    {
        return (int) $this->database->get_var($this->database->prepare("SELECT COUNT(DISTINCT CONSTRAINT_NAME) FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=%s AND TABLE_NAME=%s AND REFERENCED_TABLE_NAME IS NOT NULL", DB_NAME, $table));
    }
}
