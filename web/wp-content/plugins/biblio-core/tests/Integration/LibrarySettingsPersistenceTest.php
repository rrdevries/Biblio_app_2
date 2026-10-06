<?php

declare(strict_types=1);
namespace Biblio\Core\Tests\Integration;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbSettingsRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\{CoreSchemaHealthChecker,CoreSchema1028Migration};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Settings\SettingStale;

final class LibrarySettingsPersistenceTest extends PersistenceIntegrationTestCase
{
    public function testSchemaAndAtomicCreateResetVersionGuardPreserveScopes(): void
    {
        $health=new CoreSchemaHealthChecker($this->database,$this->tableNames);
        self::assertTrue($health->inspectForVersion(1028)->isHealthy(),$health->inspectForVersion(1028)->summary());
        $id=new LibraryId('settings-test');
        self::assertNotFalse($this->database->insert($this->tableNames->libraries(),['library_id'=>$id->value(),'library_name'=>'Instellingen test','library_type'=>'private_library','library_status'=>'active']));
        $repo=new WpdbSettingsRepository($this->database,$this->tableNames);$owner=new UserId('settings-user');
        $repo->save($id,$owner,'catalog_view','list',0);
        try {$repo->save($id,$owner,'catalog_view','grid',0);self::fail('Concurrent initial write must conflict.');} catch (SettingStale) {}
        $repo->save($id,$owner,'catalog_view',null,1);
        $repo->save($id,$owner,'catalog_view','grid',2);
        try {$repo->save($id,$owner,'catalog_view','list',1);self::fail('Stale reset must not overwrite.');} catch (SettingStale) {}
        $repo->save($id,null,'catalog_view','list',0);
        self::assertSame('grid',$repo->read($id,$owner,'catalog_view')->value);
        self::assertSame('list',$repo->read($id,null,'catalog_view')->value);
        self::assertNull($repo->read($id,new UserId('other'),'catalog_view')->value);
        $repo->save($id,$owner,'catalog_archive_visible',false,0);
        self::assertFalse($repo->read($id,$owner,'catalog_archive_visible')->value);
        // Retrying a successfully installed addition must be nondestructive.
        $upgrade=new CoreSchema1028Migration($this->database,$this->tableNames);$upgrade->assertPrecondition();$upgrade->migrate();$upgrade->assertPostcondition();
        self::assertSame(3,$repo->read($id,$owner,'catalog_view')->version);
    }
    public function testUpgradeFrom1027PreservesExistingLibraryRowsAndStartsWithNoPreferences(): void
    {
        // Only the guarded integration DB is modified. Simulate the installed
        // 1027 schema and retain a pre-existing Library across this upgrade.
        self::assertNotFalse($this->database->insert($this->tableNames->libraries(),['library_id'=>'before-upgrade','library_name'=>'Bestaande naam','library_type'=>'private_library','library_status'=>'active']));
        $before=$this->database->get_results("SELECT * FROM `{$this->tableNames->libraries()}` ORDER BY library_id",ARRAY_A);
        foreach ($this->tableNames->schema1028Additions() as $table) self::assertNotFalse($this->database->query("DROP TABLE `{$table}`"));
        $migration=new CoreSchema1028Migration($this->database,$this->tableNames);
        $migration->assertPrecondition();$migration->migrate();$migration->assertPostcondition();
        self::assertSame($before,$this->database->get_results("SELECT * FROM `{$this->tableNames->libraries()}` ORDER BY library_id",ARRAY_A));
        foreach ($this->tableNames->schema1028Additions() as $table) self::assertSame('0',$this->database->get_var("SELECT COUNT(*) FROM `{$table}`"));
    }
    public function testDatabaseRejectsCrossDomainValueAndDanglingLibrary(): void
    {
        $old=$this->database->suppress_errors(true);
        try {
            self::assertFalse($this->database->insert($this->tableNames->libraryDefaults(),['library_id'=>'missing','setting_key'=>'catalog_view','setting_value'=>'grid','setting_version'=>1]));
            self::assertNotFalse($this->database->insert($this->tableNames->libraries(),['library_id'=>'settings-test','library_name'=>'Test','library_type'=>'private_library','library_status'=>'active']));
            self::assertFalse($this->database->insert($this->tableNames->libraryDefaults(),['library_id'=>'settings-test','setting_key'=>'catalog_archive_visible','setting_value'=>'1','setting_version'=>1]));
        } finally {$this->database->suppress_errors($old);}
    }
}
