<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchemaBaselineInstaller
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
    }

    public function hasAnyCoreTable(): bool
    {
        foreach ($this->tableNames->all() as $tableName) {
            if ($this->tableExists($tableName)) {
                return true;
            }
        }

        return false;
    }

    public function install(): void
    {
        if ($this->hasAnyCoreTable()) {
            throw new CoreSchemaHealthException(new CoreSchemaHealth([
                "Formal baseline installation requires an empty Core schema; "
                . "one or more Biblio Core tables already exist",
            ]));
        }

        $libraries = $this->tableNames->libraries();
        $memberships = $this->tableNames->memberships();
        $personalLibraryDesignations = $this->tableNames
            ->personalLibraryDesignations();
        $works = $this->tableNames->works();
        $editions = $this->tableNames->editions();
        $items = $this->tableNames->items();
        $externalLoans = $this->tableNames->externalLoans();
        $readingRounds = $this->tableNames->readingRounds();
        $charsetCollate = $this->database->get_charset_collate();

        $this->execute(
            "CREATE TABLE `{$libraries}` ("
            . "library_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "library_type VARCHAR(32) NOT NULL, "
            . "library_status VARCHAR(32) NOT NULL, "
            . "PRIMARY KEY (library_id), "
            . "CONSTRAINT libraries_type_private "
            . "CHECK (library_type IN ('private_library')), "
            . "CONSTRAINT libraries_status_active "
            . "CHECK (library_status IN ('active'))"
            . ") ENGINE=InnoDB {$charsetCollate}"
        );

        $this->execute(
            "CREATE TABLE `{$memberships}` ("
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
            . "CONSTRAINT memberships_status_valid "
            . "CHECK (membership_status IN ('active', 'inactive')), "
            . "CONSTRAINT memberships_role_valid "
            . "CHECK (management_role IN ('owner', 'manager', 'member')), "
            . "CONSTRAINT memberships_access_valid "
            . "CHECK (use_access IN ('direct', 'borrow', 'view_only')), "
            . "CONSTRAINT memberships_owner_direct "
            . "CHECK (management_role <> 'owner' OR use_access = 'direct'), "
            . "CONSTRAINT memberships_permissions_json "
            . "CHECK (JSON_VALID(additional_permissions))"
            . ") ENGINE=InnoDB {$charsetCollate}"
        );

        $this->execute(
            "CREATE TABLE `{$personalLibraryDesignations}` ("
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

        $this->execute(
            "CREATE TABLE `{$works}` ("
            . "work_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "work_title VARCHAR(512) NOT NULL, "
            . "PRIMARY KEY (work_id), "
            . "CONSTRAINT works_title_non_empty "
            . "CHECK (CHAR_LENGTH(TRIM(work_title)) > 0)"
            . ") ENGINE=InnoDB {$charsetCollate}"
        );

        $this->execute(
            "CREATE TABLE `{$editions}` ("
            . "edition_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "work_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "PRIMARY KEY (edition_id), "
            . "KEY editions_by_work (work_id), "
            . "FOREIGN KEY (work_id) REFERENCES `{$works}` (work_id) "
            . "ON UPDATE RESTRICT ON DELETE RESTRICT"
            . ") ENGINE=InnoDB {$charsetCollate}"
        );

        $this->execute(
            "CREATE TABLE `{$items}` ("
            . "item_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "library_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "edition_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "item_status VARCHAR(32) NOT NULL, "
            . "PRIMARY KEY (item_id), "
            . "KEY items_by_library (library_id), "
            . "KEY items_by_edition (edition_id), "
            . "FOREIGN KEY (library_id) REFERENCES `{$libraries}` (library_id) "
            . "ON UPDATE RESTRICT ON DELETE RESTRICT, "
            . "FOREIGN KEY (edition_id) REFERENCES `{$editions}` (edition_id) "
            . "ON UPDATE RESTRICT ON DELETE RESTRICT, "
            . "CONSTRAINT items_status_active "
            . "CHECK (item_status IN ('active'))"
            . ") ENGINE=InnoDB {$charsetCollate}"
        );

        $this->execute(
            "CREATE TABLE `{$externalLoans}` ("
            . "external_loan_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "user_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "work_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "loan_status VARCHAR(32) NOT NULL, "
            . "borrowed_at DATETIME(6) NOT NULL, "
            . "due_at DATETIME(6) NULL, "
            . "PRIMARY KEY (external_loan_id), "
            . "KEY external_loans_by_user (user_id), "
            . "KEY external_loans_by_work (work_id), "
            . "FOREIGN KEY (work_id) REFERENCES `{$works}` (work_id) "
            . "ON UPDATE RESTRICT ON DELETE RESTRICT, "
            . "CONSTRAINT external_loans_user_non_empty "
            . "CHECK (CHAR_LENGTH(TRIM(user_id)) > 0), "
            . "CONSTRAINT external_loans_status_active "
            . "CHECK (loan_status IN ('active'))"
            . ") ENGINE=InnoDB {$charsetCollate}"
        );

        $this->execute(
            "CREATE TABLE `{$readingRounds}` ("
            . "reading_round_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "user_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "work_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NOT NULL, "
            . "item_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NULL, "
            . "external_loan_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin NULL, "
            . "round_status VARCHAR(32) NOT NULL, "
            . "started_at DATETIME(6) NOT NULL, "
            . "active_item_user_id VARCHAR(191) CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_bin GENERATED ALWAYS AS ("
            . "CASE WHEN round_status = 'active' AND item_id IS NOT NULL "
            . "THEN user_id ELSE NULL END"
            . ") STORED, "
            . "active_external_loan_user_id VARCHAR(191) "
            . "CHARACTER SET utf8mb4 COLLATE utf8mb4_bin "
            . "GENERATED ALWAYS AS ("
            . "CASE WHEN round_status = 'active' "
            . "AND external_loan_id IS NOT NULL "
            . "THEN user_id ELSE NULL END"
            . ") STORED, "
            . "PRIMARY KEY (reading_round_id), "
            . "KEY reading_rounds_by_user (user_id), "
            . "KEY reading_rounds_by_work (work_id), "
            . "UNIQUE KEY one_active_item_round_per_user "
            . "(active_item_user_id, item_id), "
            . "UNIQUE KEY one_active_external_round_per_user "
            . "(active_external_loan_user_id, external_loan_id), "
            . "FOREIGN KEY (work_id) REFERENCES `{$works}` (work_id) "
            . "ON UPDATE RESTRICT ON DELETE RESTRICT, "
            . "FOREIGN KEY (item_id) REFERENCES `{$items}` (item_id) "
            . "ON UPDATE RESTRICT ON DELETE RESTRICT, "
            . "FOREIGN KEY (external_loan_id) "
            . "REFERENCES `{$externalLoans}` (external_loan_id) "
            . "ON UPDATE RESTRICT ON DELETE RESTRICT, "
            . "CONSTRAINT reading_rounds_status_active "
            . "CHECK (round_status IN ('active')), "
            . "CONSTRAINT reading_rounds_source_xor "
            . "CHECK ((item_id IS NOT NULL AND external_loan_id IS NULL) "
            . "OR (item_id IS NULL AND external_loan_id IS NOT NULL))"
            . ") ENGINE=InnoDB {$charsetCollate}"
        );
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

    private function execute(string $sql): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not install formal Biblio Core schema baseline: "
                . $this->database->last_error
            );
        }
    }
}
