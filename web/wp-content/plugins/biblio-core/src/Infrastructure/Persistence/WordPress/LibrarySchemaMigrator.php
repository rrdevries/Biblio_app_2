<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use wpdb;

final readonly class LibrarySchemaMigrator
{
    public const CURRENT_VERSION = 2;
    public const VERSION_OPTION = "biblio_core_library_schema_version";

    public function __construct(
        private wpdb $database,
        private LibraryTableNames $tableNames
    ) {
    }

    public function migrate(): void
    {
        $installedVersion = (int) get_option(self::VERSION_OPTION, 0);

        if ($installedVersion >= self::CURRENT_VERSION) {
            return;
        }

        $libraries = $this->tableNames->libraries();
        $memberships = $this->tableNames->memberships();
        $personalLibraryDesignations = $this->tableNames
            ->personalLibraryDesignations();
        $charsetCollate = $this->database->get_charset_collate();

        $this->execute(
            "CREATE TABLE IF NOT EXISTS `{$libraries}` ("
            . "library_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "library_type VARCHAR(32) NOT NULL, "
            . "library_status VARCHAR(32) NOT NULL, "
            . "PRIMARY KEY (library_id), "
            . "CHECK (library_type IN ('private_library')), "
            . "CHECK (library_status IN ('active'))"
            . ") ENGINE=InnoDB {$charsetCollate}"
        );

        $this->execute(
            "CREATE TABLE IF NOT EXISTS `{$memberships}` ("
            . "library_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "user_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "membership_status VARCHAR(32) NOT NULL, "
            . "management_role VARCHAR(32) NOT NULL, "
            . "use_access VARCHAR(32) NOT NULL, "
            . "additional_permissions LONGTEXT CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "active_owner_library_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin GENERATED ALWAYS AS ("
            . "CASE WHEN management_role = 'owner' "
            . "AND membership_status = 'active' "
            . "THEN library_id ELSE NULL END"
            . ") STORED, "
            . "PRIMARY KEY (library_id, user_id), "
            . "UNIQUE KEY one_active_owner (active_owner_library_id), "
            . "FOREIGN KEY (library_id) REFERENCES `{$libraries}` (library_id) "
            . "ON UPDATE RESTRICT ON DELETE RESTRICT, "
            . "CHECK (membership_status IN ('active', 'inactive')), "
            . "CHECK (management_role IN ('owner', 'manager', 'member')), "
            . "CHECK (use_access IN ('direct', 'borrow', 'view_only')), "
            . "CHECK (management_role <> 'owner' OR use_access = 'direct'), "
            . "CHECK (JSON_VALID(additional_permissions))"
            . ") ENGINE=InnoDB {$charsetCollate}"
        );

        $this->execute(
            "CREATE TABLE IF NOT EXISTS `{$personalLibraryDesignations}` ("
            . "user_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "library_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "PRIMARY KEY (user_id), "
            . "UNIQUE KEY one_personal_user_per_library (library_id), "
            . "FOREIGN KEY (library_id) REFERENCES `{$libraries}` (library_id) "
            . "ON UPDATE RESTRICT ON DELETE RESTRICT, "
            . "FOREIGN KEY (library_id, user_id) "
            . "REFERENCES `{$memberships}` (library_id, user_id) "
            . "ON UPDATE RESTRICT ON DELETE RESTRICT"
            . ") ENGINE=InnoDB {$charsetCollate}"
        );

        update_option(
            self::VERSION_OPTION,
            (string) self::CURRENT_VERSION,
            false
        );
    }

    private function execute(string $sql): void
    {
        if ($this->database->query($sql) === false) {
            throw new PersistenceException(
                "Could not migrate Library schema: "
                . $this->database->last_error
            );
        }
    }
}
