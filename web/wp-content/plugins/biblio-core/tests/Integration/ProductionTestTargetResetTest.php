<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Migration\Cutover\{ProductionAuthorization, ProductionTestResetAuthorization, RehearsalContract, RehearsalDatabaseTransport, RehearsalFailure};
use Biblio\Core\Application\Migration\Runner\{MigrationBuildProvenance, MigrationEnvironment};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\{MariaDbProductionTransport, ProductionBackupDirectory, ProductionEvidenceDirectory, WpdbProductionMigrationTarget, WpdbProductionTestTargetReset};
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Tests\Support\RehearsalFixture;

require_once dirname(__DIR__) . '/Support/RehearsalFixture.php';

final class ProductionTestTargetResetTest extends PersistenceIntegrationTestCase
{
    public function testBoundedNativeResetRollbackBackupRestoreAndIdentityPreservation(): void
    {
        self::assertSame('biblio_core_test', DB_NAME);
        self::assertMatchesRegularExpression('/^biblio-v2-cutover-[a-f0-9]{12}$/D', (string) getenv('DDEV_SITENAME'));
        $directory = sys_get_temp_dir() . '/biblio-production-test-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        foreach (['web', 'evidence', 'backups'] as $child) { mkdir($directory . '/' . $child, 0700); }
        $original = $GLOBALS['wpdb'];
        $flag = getenv('BIBLIO_PRODUCTION_TEST');
        $primaryName = 'biblio_prod_test_' . bin2hex(random_bytes(6));
        $probeName = 'biblio_prod_probe_' . bin2hex(random_bytes(6));
        $users = [];
        $admin = new \wpdb('root', 'root', 'biblio_core_test', 'db');
        self::assertSame('0', $admin->get_var('SELECT @@GLOBAL.read_only'));
        try {
            $app = (new ProductionComposition($this->database))->application();
            foreach (['preserve', 'discard'] as $side) {
                $id = wp_create_user('reset-' . $side . '-' . bin2hex(random_bytes(5)), 'synthetic-only');
                self::assertIsInt($id);
                $users[] = $id;
                $user = new UserId((string) $id);
                $library = $app->personalMigrationTargets()->bootstrap($user)->libraryId();
                $scope[$side . '_user_id'] = $user->value();
                $scope[$side . '_library_id'] = $library->value();
            }
            self::assertSame(1, $this->database->insert($this->tableNames->works(), ['work_id' => 'reset-work', 'work_title' => 'synthetic-private-reset']));
            self::assertSame(1, $this->database->insert($this->tableNames->editions(), ['edition_id' => 'reset-edition', 'work_id' => 'reset-work', 'edition_title' => 'synthetic-private-edition']));
            foreach ([$primaryName, $probeName] as $name) {
                $collation = $name === $primaryName ? 'utf8mb4_general_ci' : 'utf8mb4_unicode_ci';
                self::assertNotFalse($admin->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE {$collation}"));
            }
            $primary = new \wpdb('root', 'root', $primaryName, 'db');
            $primary->set_prefix($this->database->prefix);
            $primary->suppress_errors(true);
            // Fixture construction only, before any reset invocation.
            $primary->query('SET FOREIGN_KEY_CHECKS=0');
            foreach ($this->database->get_col('SHOW TABLES') as $table) {
                self::assertNotFalse($primary->query($this->database->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_A)['Create Table']));
                foreach ($this->database->get_results("SELECT * FROM `{$table}`", ARRAY_A) as $row) { self::assertNotFalse($primary->insert($table, $row)); }
            }
            $primary->query('SET FOREIGN_KEY_CHECKS=1');
            foreach ($this->database->get_results('SHOW TRIGGERS', ARRAY_A) as $trigger) {
                self::assertNotFalse($primary->query($this->database->get_row("SHOW CREATE TRIGGER `{$trigger['Trigger']}`", ARRAY_A)['SQL Original Statement']));
            }
            $GLOBALS['wpdb'] = $primary;
            wp_cache_flush();
            $app = (new ProductionComposition($primary))->application();
            $environment = new class implements MigrationEnvironment {
                public function assertHealthy(): void {}
                public function provenance(): MigrationBuildProvenance { return new MigrationBuildProvenance('v2.001', 1026, '2.51.0', str_repeat('a', 40), false); }
            };
            putenv('BIBLIO_PRODUCTION_TEST=1');
            $identity = ['purpose' => 'production-simulation', 'project' => getenv('DDEV_SITENAME'), 'database' => $primaryName,
                'root' => $directory, 'url' => get_option('siteurl'), 'git_sha' => str_repeat('a', 40),
                'target_user_id' => $scope['preserve_user_id'], 'target_library_id' => $scope['preserve_library_id']];
            $target = new WpdbProductionMigrationTarget($primary, $app->personalMigrationTargets(), $environment,
                new UserId($scope['preserve_user_id']), new \Biblio\Core\Library\LibraryId($scope['preserve_library_id']), $identity, $directory . '/web/.maintenance');
            $evidence = new ProductionEvidenceDirectory($directory . '/evidence');
            $probe = new \wpdb('root', 'root', $probeName, 'db');
            $probe->set_prefix($primary->prefix);
            $probe->suppress_errors(true);
            self::assertSame('utf8mb4_unicode_ci', $probe->get_var("SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()"));
            $probeTransport = new MariaDbProductionTransport($probe, $probeName, 'db', 'root', 'root', $target, true);
            $backups = new ProductionBackupDirectory($directory . '/backups', $target,
                new MariaDbProductionTransport($primary, $primaryName, 'db', 'root', 'root', $target),
                $probeTransport, $evidence);
            $reset = new WpdbProductionTestTargetReset($primary, $target, $backups, $evidence);
            $before = $target->fingerprint();
            self::assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci'], $before['database_metadata']);
            $wrong = $before['tables'];
            $wrong[$this->tableNames->works()]['rows']++;
            try { $reset->prepare($wrong, $scope); self::fail('Unreviewed population accepted'); }
            catch (RehearsalFailure $e) { self::assertSame('reviewed_test_population_changed', $e->reason); }
            $packet = $reset->prepare($before['tables'], $scope);
            self::assertSame($before, $target->fingerprint());
            self::assertTrue($packet['backup']['independent_restore_verified']);
            self::assertSame('PRE_RESET', $packet['backup']['phase']);
            self::assertSame('utf8mb4_general_ci', $probe->get_var("SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()"));
            self::assertSame($before['database_metadata'], $probeTransport->fingerprint()['database_metadata']);
            self::assertSame($before['trigger_sha256'], $probeTransport->fingerprint()['trigger_sha256']);
            self::assertSame($before, $probeTransport->fingerprint());
            $mismatchingProbe = new class($probeTransport, $probe, $probeName) implements RehearsalDatabaseTransport {
                public function __construct(private RehearsalDatabaseTransport $transport, private \wpdb $db, private string $database) {}
                public function databaseId(): string { return $this->transport->databaseId(); }
                public function fingerprint(): array { return $this->transport->fingerprint(); }
                public function export(string $path): void { $this->transport->export($path); }
                public function import(string $path): void
                {
                    $this->transport->import($path);
                    RehearsalContract::require($this->db->query("ALTER DATABASE `{$this->database}` COLLATE utf8mb4_unicode_ci") !== false, 'synthetic_probe_change_failed');
                }
            };
            $mismatchBackups = new ProductionBackupDirectory($directory . '/backups', $target,
                new MariaDbProductionTransport($primary, $primaryName, 'db', 'root', 'root', $target), $mismatchingProbe, $evidence);
            try { $mismatchBackups->create('PRE_RESET', $packet['binding']); self::fail('Changed probe collation accepted'); }
            catch (RehearsalFailure $e) { self::assertSame('backup_restore_proof_failed', $e->reason); }
            self::assertSame($before, $target->fingerprint());
            try { $backups->restore($packet['backup'], $packet['binding']); self::fail('PRE_RESET accepted as cutover rollback'); }
            catch (RehearsalFailure $e) { self::assertSame('rollback_requires_pre_apply', $e->reason); }
            try { new ProductionAuthorization($packet, ['purpose'=>'production-cutover', 'packet_digest'=>ProductionAuthorization::digest($packet), 'confirmation'=>'AUTHORIZE PRODUCTION ' . ProductionAuthorization::digest($packet)]); self::fail('PRE_RESET grants FINAL authority'); }
            catch (RehearsalFailure $e) { self::assertSame('pre_apply_backup_required', $e->reason); }
            $digest = ProductionTestResetAuthorization::digest($packet);
            $approval = ['purpose' => 'production-test-target-reset', 'packet_digest' => $digest, 'confirmation' => 'RESET TEST TARGET ' . $digest];
            $auth = new ProductionTestResetAuthorization($packet, $approval);
            $changed = $packet;
            $changed['binding']['scope']['discard_user_id'] = 'unknown';
            try { new ProductionTestResetAuthorization($changed, $approval); self::fail('Changed scope accepted'); }
            catch (RehearsalFailure $e) { self::assertSame('test_reset_authorization_changed', $e->reason); }
            $primary->query("UPDATE `{$this->tableNames->works()}` SET work_title='changed'");
            try { $reset->execute($auth); self::fail('Changed population accepted'); }
            catch (RehearsalFailure $e) { self::assertSame('reviewed_test_population_changed', $e->reason); }
            $primary->query("UPDATE `{$this->tableNames->works()}` SET work_title='synthetic-private-reset'");
            $probe->close();
            $admin->close();
            $deleted = false;
            $editionDeleteSeen = false;
            $editionsTable = $this->tableNames->editions();
            $fault = static function (string $sql) use (&$deleted, &$editionDeleteSeen, $primary, $editionsTable): string {
                if (str_starts_with($sql, 'DELETE FROM')) {
                    if ($editionDeleteSeen) {
                        self::assertSame('0', $primary->get_var("SELECT COUNT(*) FROM `{$editionsTable}`"));
                        $deleted = true;
                        throw new \RuntimeException('synthetic-private-fault');
                    }
                    if ($sql === "DELETE FROM `{$editionsTable}`") { $editionDeleteSeen = true; }
                }
                return $sql;
            };
            add_filter('query', $fault);
            try { $reset->execute($auth); self::fail('Injected failure accepted'); }
            catch (\RuntimeException $e) { self::assertSame('synthetic-private-fault', $e->getMessage()); }
            finally { remove_filter('query', $fault); }
            self::assertTrue($deleted);
            self::assertSame('1', $primary->get_var("SELECT COUNT(*) FROM `{$editionsTable}`"));
            self::assertSame($before, $target->fingerprint());
            self::assertSame('1', $primary->get_var('SELECT @@GLOBAL.read_only'));
            $result = $reset->execute($auth);
            self::assertSame('TEST_TARGET_RESET_VERIFIED', $result['status']);
            $target->assertEmpty();
            foreach ([$primary->users, $primary->usermeta, $primary->options] as $table) {
                self::assertSame($before['tables'][$table], $result['after']['tables'][$table]);
            }
            self::assertSame(1, $result['cleared_counts'][$this->tableNames->libraries()]);
            self::assertSame(1, $result['cleared_counts'][$this->tableNames->works()]);
            self::assertSame(1, $result['cleared_counts'][$this->tableNames->editions()]);
            try { $reset->restore($auth, 'yes'); self::fail('Implicit restore accepted'); }
            catch (RehearsalFailure $e) { self::assertSame('explicit_test_reset_restore_required', $e->reason); }
            $reset->restore($auth, 'RESTORE TEST TARGET ' . $digest);
            self::assertSame($before, $target->fingerprint());
            self::assertNotFalse($primary->query("DROP TABLE `{$primary->options}`"));
            $reset->restore($auth, 'RESTORE TEST TARGET ' . $digest);
            self::assertSame($before, $target->fingerprint());
            self::assertNotFalse($primary->query("DROP DATABASE `{$primaryName}`"));
            self::assertNotFalse($primary->query("DROP DATABASE `{$probeName}`"));
            $primary->close();
            $primary = new \wpdb('root', 'root', 'information_schema', 'db');
            $primary->set_prefix($this->database->prefix);
            $primary->suppress_errors(true);
            $target = new WpdbProductionMigrationTarget($primary, $app->personalMigrationTargets(), $environment,
                new UserId($scope['preserve_user_id']), new \Biblio\Core\Library\LibraryId($scope['preserve_library_id']), $identity, $directory . '/web/.maintenance');
            $target->prepareTestResetRestore($auth);
            $backups = new ProductionBackupDirectory($directory . '/backups', $target,
                new MariaDbProductionTransport($primary, $primaryName, 'db', 'root', 'root', $target, false, $auth), null, $evidence);
            $reset = new WpdbProductionTestTargetReset($primary, $target, $backups, $evidence);
            $reset->restore($auth, 'RESTORE TEST TARGET ' . $digest);
            self::assertSame($before, $target->fingerprint());
            self::assertSame('1', $primary->get_var('SELECT @@GLOBAL.read_only'));
            foreach (glob($directory . '/evidence/*/*.json') ?: [] as $path) {
                self::assertStringNotContainsString('synthetic-private', (string) file_get_contents($path));
            }
        } finally {
            self::assertMatchesRegularExpression('/^biblio-v2-cutover-[a-f0-9]{12}$/D', (string) getenv('DDEV_SITENAME'));
            $admin = new \wpdb('root', 'root', 'biblio_core_test', 'db');
            $admin->query('SET GLOBAL read_only=OFF');
            $GLOBALS['wpdb'] = $original;
            wp_cache_flush();
            foreach ([$primaryName, $probeName] as $name) { $admin->query("DROP DATABASE IF EXISTS `{$name}`"); }
            foreach ($users as $id) { $this->database->delete($this->database->usermeta, ['user_id'=>$id]); $this->database->delete($this->database->users, ['ID'=>$id]); }
            foreach ([$primary ?? null, $probe ?? null, $admin] as $connection) { if ($connection instanceof \wpdb) { $connection->close(); } }
            putenv($flag === false ? 'BIBLIO_PRODUCTION_TEST' : 'BIBLIO_PRODUCTION_TEST=' . $flag);
            RehearsalFixture::remove($directory);
        }
    }
}
