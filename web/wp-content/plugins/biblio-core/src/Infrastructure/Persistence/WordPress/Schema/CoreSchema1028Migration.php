<?php

declare(strict_types=1);
namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1028Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $health;
    public function __construct(private wpdb $database,private CoreTableNames $tables) { $this->health=new CoreSchemaHealthChecker($database,$tables); }
    public function sourceVersion(): int { return 1027; }
    public function targetVersion(): int { return 1028; }
    public function assertPrecondition(): void
    {
        foreach ([$this->health->inspectForVersion(1027),$this->health->inspectExistingSchema1028Additions()] as $health)
            if (!$health->isHealthy()) throw new CoreSchemaHealthException($health);
    }
    public function migrate(): void
    {
        foreach ([false,true] as $personal) {
            $table=$personal ? $this->tables->personalSettings() : $this->tables->libraryDefaults();
            if ((int)$this->database->get_var($this->database->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table)) > 0) continue;
            $id='VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin';
            $label=$personal ? 'personal_settings' : 'library_defaults';
            $check=$personal
                ? "setting_key IN ('catalog_view','catalog_archive_visible') AND (setting_value IS NULL OR setting_key='catalog_view' AND setting_value IN ('grid','list') OR setting_key='catalog_archive_visible' AND setting_value IN ('0','1'))"
                : "setting_key='catalog_view' AND (setting_value IS NULL OR setting_value IN ('grid','list'))";
            $sql="CREATE TABLE `{$table}` (".($personal ? "user_id {$id} NOT NULL," : '')
                ."library_id {$id} NOT NULL,setting_key VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                ."setting_value VARCHAR(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,setting_version BIGINT UNSIGNED NOT NULL,"
                .($personal ? 'PRIMARY KEY(user_id,library_id,setting_key),KEY library_scope (library_id),' : 'PRIMARY KEY(library_id,setting_key),')
                ."CONSTRAINT {$label}_library_fk FOREIGN KEY(library_id) REFERENCES `{$this->tables->libraries()}` (library_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                ."CONSTRAINT {$label}_value CHECK ({$check}),CONSTRAINT {$label}_version CHECK (setting_version>0)"
                .($personal ? ",CONSTRAINT {$label}_user CHECK (CHAR_LENGTH(TRIM(user_id))>0)" : '')
                .") ENGINE=InnoDB {$this->database->get_charset_collate()}";
            if ($this->database->query($sql) === false) throw new CoreSchemaMigrationException('Could not create settings storage.');
        }
    }
    public function assertPostcondition(): void { $health=$this->health->inspectForVersion(1028);if (!$health->isHealthy()) throw new CoreSchemaHealthException($health); }
}
