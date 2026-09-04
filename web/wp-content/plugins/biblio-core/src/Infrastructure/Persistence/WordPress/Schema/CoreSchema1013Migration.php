<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1013Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $health;

    public function __construct(private wpdb $database, private CoreTableNames $tables)
    {
        $this->health = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1012; }
    public function targetVersion(): int { return 1013; }

    public function assertPrecondition(): void
    {
        $source = $this->health->inspectForVersion(1012);
        if (!$source->isHealthy()) { throw new CoreSchemaHealthException($source); }
        $additions = $this->health->inspectExistingSchema1013Additions();
        if (!$additions->isHealthy()) {
            throw new CoreSchemaMigrationException("Schema 1013 retry found an unknown Collection state: " . $additions->summary());
        }
    }

    public function migrate(): void
    {
        $collation = $this->database->get_charset_collate();
        $libraries = $this->tables->libraries();
        $items = $this->tables->items();
        $collections = $this->tables->collections();
        if (!$this->tableExists($collections)) {
            $this->execute(
                "CREATE TABLE `{$collections}` ("
                    . "library_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "collection_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "collection_name VARCHAR(80) NOT NULL,"
                    . "normalized_name VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "description VARCHAR(300) NULL,"
                    . "collection_status VARCHAR(16) NOT NULL,"
                    . "collection_position BIGINT UNSIGNED NOT NULL,"
                    . "collection_version BIGINT UNSIGNED NOT NULL,"
                    . "created_at DATETIME(6) NOT NULL,"
                    . "updated_at DATETIME(6) NOT NULL,"
                    . "active_normalized_name VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin GENERATED ALWAYS AS (CASE WHEN collection_status='active' THEN normalized_name ELSE NULL END) STORED,"
                    . "PRIMARY KEY (library_id,collection_id),"
                    . "UNIQUE KEY collections_active_name (library_id,active_normalized_name),"
                    . "KEY collections_active_order (library_id,collection_status,collection_position,collection_id),"
                    . "CONSTRAINT collections_library_fk FOREIGN KEY (library_id) REFERENCES `{$libraries}` (library_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT collection_name_non_empty CHECK (CHAR_LENGTH(TRIM(collection_name)) > 0),"
                    . "CONSTRAINT collection_normalized_name_non_empty CHECK (CHAR_LENGTH(TRIM(normalized_name)) > 0),"
                    . "CONSTRAINT collection_status_supported CHECK (collection_status IN ('active','archived')),"
                    . "CONSTRAINT collection_position_positive CHECK (collection_position >= 1),"
                    . "CONSTRAINT collection_version_positive CHECK (collection_version >= 1),"
                    . "CONSTRAINT collection_updated_after_created CHECK (updated_at >= created_at)"
                    . ") ENGINE=InnoDB {$collation}",
                "Collections"
            );
        }

        $memberships = $this->tables->collectionMemberships();
        if (!$this->tableExists($memberships)) {
            $this->execute(
                "CREATE TABLE `{$memberships}` ("
                    . "library_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "membership_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "collection_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "item_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "membership_status VARCHAR(16) NOT NULL,"
                    . "item_position BIGINT UNSIGNED NOT NULL,"
                    . "added_at DATETIME(6) NOT NULL,"
                    . "ended_at DATETIME(6) NULL,"
                    . "end_reason VARCHAR(32) NULL,"
                    . "active_item_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin GENERATED ALWAYS AS (CASE WHEN membership_status='active' THEN item_id ELSE NULL END) STORED,"
                    . "PRIMARY KEY (library_id,membership_id),"
                    . "UNIQUE KEY collection_one_active_item (library_id,collection_id,active_item_id),"
                    . "KEY collection_memberships_active_order (library_id,collection_id,membership_status,item_position),"
                    . "KEY collection_memberships_by_item (library_id,item_id,membership_status),"
                    . "KEY collection_memberships_history (library_id,item_id,end_reason,ended_at),"
                    . "CONSTRAINT collection_membership_collection_fk FOREIGN KEY (library_id,collection_id) REFERENCES `{$collections}` (library_id,collection_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT collection_membership_item_fk FOREIGN KEY (library_id,item_id) REFERENCES `{$items}` (library_id,item_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT collection_membership_status_supported CHECK (membership_status IN ('active','inactive')),"
                    . "CONSTRAINT collection_membership_position_positive CHECK (item_position >= 1),"
                    . "CONSTRAINT collection_membership_end_pair CHECK ((ended_at IS NULL) = (end_reason IS NULL)),"
                    . "CONSTRAINT collection_membership_lifecycle CHECK ((membership_status='active' AND ended_at IS NULL) OR (membership_status='inactive' AND ended_at IS NOT NULL)),"
                    . "CONSTRAINT collection_membership_end_reason_supported CHECK (end_reason IS NULL OR end_reason IN ('removed','item_archived')),"
                    . "CONSTRAINT collection_membership_time_order CHECK (ended_at IS NULL OR ended_at >= added_at)"
                    . ") ENGINE=InnoDB {$collation}",
                "Collection memberships"
            );
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->health->inspectForVersion(1013);
        if (!$health->isHealthy()) { throw new CoreSchemaHealthException($health); }
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s", DB_NAME, $table
        )) === 1;
    }

    private function execute(string $sql, string $component): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException("Could not create schema 1013 component {$component}: " . $this->database->last_error);
        }
    }
}
