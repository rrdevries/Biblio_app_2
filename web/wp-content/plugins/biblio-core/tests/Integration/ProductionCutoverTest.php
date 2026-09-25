<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Migration\Cutover\{ProductionAuthorization, RehearsalFailure};
use Biblio\Core\Application\Migration\Runner\{MigrationBuildProvenance, MigrationEnvironment, MigrationPlanningTarget, DeterministicJson};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\{MariaDbProductionTransport, ProductionBackupDirectory, ProductionCutoverComposition, ProductionEvidenceDirectory, RehearsalComposition, WpdbProductionMigrationTarget};
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Tests\Support\RehearsalFixture;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname(__DIR__) . '/Support/RehearsalFixture.php';

/** ALL native/server mutations remain inside a positively identified disposable DDEV server. */
final class ProductionCutoverTest extends PersistenceIntegrationTestCase
{
    public function testStandaloneBootstrapSupportsReadOnlySchemaHealthWithoutWordPressLifecycle(): void
    {
        self::assertMatchesRegularExpression('/^biblio-v2-cutover-[a-f0-9]{12}$/D', (string) getenv('DDEV_SITENAME'));
        $code = <<<'PHP'
$productionDatabase = 'db';
require '/var/www/html/scripts/production-cutover-bootstrap.php';
require '/var/www/html/web/wp-content/plugins/biblio-core/vendor/autoload.php';
$tables = new Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames('wp_');
$state = new Biblio\Core\Infrastructure\Migration\RehearsalDatabaseState($wpdb, $tables);
$before = $state->fingerprint();
$healthy = (new Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaHealthChecker($wpdb, $tables))->inspectForVersion(1026)->isHealthy();
echo json_encode(['database'=>DB_NAME, 'healthy'=>$healthy, 'unchanged'=>$before === $state->fingerprint(), 'init_count'=>did_action('init')], JSON_THROW_ON_ERROR);
PHP;
        $process = proc_open([PHP_BINARY, '-r', $code], [1=>['pipe', 'w'], 2=>['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
        self::assertSame('', $stderr);
        self::assertSame(['database'=>'db', 'healthy'=>true, 'unchanged'=>true, 'init_count'=>0], json_decode($stdout, true, 512, JSON_THROW_ON_ERROR));
    }

    public static function verificationModes(): array { return [[false], [true]]; }

    #[DataProvider('verificationModes')]
    public function testNativeProductionSimulationApplyBackupDenialAndDeliberateRestore(bool $failVerification): void
    {
        self::assertSame('biblio_core_test', DB_NAME);
        self::assertMatchesRegularExpression('/^biblio-v2-cutover-[a-f0-9]{12}$/D', (string) getenv('DDEV_SITENAME'));
        $directory = sys_get_temp_dir() . '/biblio-production-test-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        foreach (['web', 'evidence', 'backups'] as $child) { mkdir($directory . '/' . $child, 0700); }
        $originalGlobal = $GLOBALS['wpdb'];
        $flag = getenv('BIBLIO_PRODUCTION_TEST');
        $admin = new \wpdb('root', 'root', 'biblio_core_test', 'db');
        $primaryName = 'biblio_prod_test_' . bin2hex(random_bytes(6));
        $probeName = 'biblio_prod_probe_' . bin2hex(random_bytes(6));
        self::assertSame('0', $admin->get_var('SELECT @@GLOBAL.read_only'));
        $userId = 0;
        try {
            $userId = wp_create_user('prod-synthetic-' . bin2hex(random_bytes(5)), 'synthetic-only');
            $user = new UserId((string) $userId);
            $app = (new ProductionComposition($this->database))->application();
            $library = $app->personalMigrationTargets()->bootstrap($user)->libraryId();
            foreach ([$primaryName, $probeName] as $name) {
                self::assertNotFalse($admin->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"));
                self::assertNotFalse($admin->query("GRANT ALL ON `{$name}`.* TO 'db'@'%'") );
            }
            $primary = new \wpdb('root', 'root', $primaryName, 'db');
            $primary->set_prefix($this->database->prefix);
            $primary->suppress_errors(true);
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
                'root' => $directory, 'url' => get_option('siteurl'), 'git_sha' => str_repeat('a', 40), 'target_user_id' => $user->value(), 'target_library_id' => $library->value()];
            $target = new WpdbProductionMigrationTarget($primary, $app->personalMigrationTargets(), $environment, $user, $library, $identity, $directory . '/web/.maintenance');
            $source = RehearsalFixture::source($directory, true, true, false, true);
            $inspection = (new \Biblio\Core\Infrastructure\Migration\FinalSourceInspector(new \Biblio\Core\Infrastructure\Migration\FilesystemMigrationSourcePackageFactory()))->inspect($source->intake->extractionRoot(), new \Biblio\Core\Infrastructure\Migration\CurrentV1SourceAdapter());
            $snapshot = (new \Biblio\Core\Infrastructure\Migration\CurrentV1FinalSourceSnapshotBuilder())->build($inspection, $source->intake->identity());
            $report = (new \Biblio\Core\Application\Migration\Cutover\FinalSourceDriftEngine())->compare($snapshot, $snapshot);
            $priorPath = $directory . '/source-evidence.json';
            file_put_contents($priorPath, json_encode(['intake'=>$source->intake->toArray(), 'compatibility_report'=>$report->toArray(), 'contract_bundle_sha256'=>$source->bundle->digest()], JSON_THROW_ON_ERROR));
            $loaded = \Biblio\Core\Infrastructure\Migration\ProductionSourceLoader::load(['intake_evidence'=>$priorPath, 'intake_evidence_sha256'=>hash_file('sha256',$priorPath),
                'extraction_root'=>$source->intake->extractionRoot(), 'reference_root'=>$source->intake->extractionRoot(), 'archive'=>$directory.'/synthetic.zip',
                'bundle_sha256'=>$source->bundle->digest(), 'review'=>$source->planningContext()->review]);
            self::assertSame($source->planningContext()->reviewDigest(), $loaded->reviewDigest());
            $evidence = new ProductionEvidenceDirectory($directory . '/evidence');
            $probe = new \wpdb('root', 'root', $probeName, 'db');
            $probe->set_prefix($primary->prefix);
            $backups = new ProductionBackupDirectory($directory . '/backups', $target,
                new MariaDbProductionTransport($primary, $primaryName, 'db', 'root', 'root', $target),
                new MariaDbProductionTransport($probe, $probeName, 'db', 'root', 'root', $target, true), $evidence);
            $guard = (new ProductionCutoverComposition($primary, $app, $environment))->create($source->planningContext(), $target, $backups, $evidence, $user, $library);
            $before = $target->fingerprint();
            $packet = $guard->preflight();
            self::assertSame($before, $target->fingerprint());
            self::assertTrue($packet['backup']['independent_restore_verified']);
            self::assertFileDoesNotExist($directory . '/web/.maintenance');
            $planner = (new RehearsalComposition($primary, $app, $environment))->planner($source);
            $same = $planner->prepare($planner->inspectSource($source->intake->extractionRoot(), $source->intake->identity()->adapterId()),
                new MigrationPlanningTarget($app->personalMigrationTargets()->validate($user, $library)));
            self::assertSame($packet['binding']['plan']['plan_set_digest'], $same->planSetDigest());
            $digest = ProductionAuthorization::digest($packet);
            $approval = ['purpose' => 'production-cutover', 'packet_digest' => $digest, 'confirmation' => 'AUTHORIZE PRODUCTION ' . $digest];
            $auth = new ProductionAuthorization($packet, $approval);
            $changed = $packet['backup'];
            $changed['sha256'] = str_repeat('f', 64);
            try { $backups->restore($changed, $packet['binding']); self::fail('Changed backup accepted'); }
            catch (RehearsalFailure) { self::assertSame($before, $target->fingerprint()); }
            $ordinary = new \wpdb('db', 'db', $primaryName, 'db');
            $ordinary->suppress_errors(true);
            $target->acquire();
            try {
                try { $target->blockWrites(); self::fail('Other privileged session accepted'); }
                catch (RehearsalFailure $failure) { self::assertSame('production_other_operator_active', $failure->reason); }
            } finally { $target->release(); }
            $probe->close();
            $admin->close();
            $ordinary->query('START TRANSACTION');
            self::assertNotFalse($ordinary->query("UPDATE `{$primary->options}` SET option_value=option_value WHERE option_name='siteurl'"));
            $target->acquire();
            try {
                try { $target->blockWrites(); self::fail('Inflight transaction accepted'); }
                catch (RehearsalFailure $failure) { self::assertSame('production_writer_drain_failed', $failure->reason); }
            } finally { $ordinary->query('ROLLBACK'); $target->release(); }
            self::assertSame('1', $primary->get_var('SELECT @@GLOBAL.read_only'));
            if ($failVerification) {
                $reflection = new \ReflectionClass($guard);
                $failing = $reflection->newInstanceWithoutConstructor();
                foreach ($reflection->getProperties() as $property) {
                    $property->setValue($failing, $property->getName() === 'products'
                        ? new class implements \Biblio\Core\Application\Migration\Cutover\RehearsalProductVerifier {
                            public function verify(\Biblio\Core\Application\Migration\MigrationRun $run, \Biblio\Core\Application\Migration\Runner\PreparedMigrationPlan $prepared): array
                            { throw new \RuntimeException('synthetic-private-verification-canary'); }
                        } : $property->getValue($guard));
                }
                try { $failing->execute($auth); self::fail('Verification failure accepted'); }
                catch (\RuntimeException $failure) { self::assertSame('synthetic-private-verification-canary', $failure->getMessage()); }
                self::assertCount(1, glob($directory . '/evidence/production-failure-*/*.json'));
            } else {
                $result = $guard->execute($auth);
                self::assertTrue($result['writes_remain_blocked']);
                self::assertContains('post_apply_product_verification', $result['phases']);
                self::assertContains('reconciliation', $result['phases']);
            }
            self::assertNotSame($before, $target->fingerprint());
            $fresh = new \wpdb('db', 'db', $primaryName, 'db');
            $fresh->suppress_errors(true);
            foreach ([$ordinary, $fresh] as $connection) {
                self::assertFalse($connection->query("UPDATE `{$primary->options}` SET option_value=option_value WHERE 1=0"));
                self::assertStringContainsString('read-only', strtolower($connection->last_error));
                self::assertNotNull($connection->get_var("SELECT COUNT(*) FROM `{$primary->options}`"));
            }
            try { $guard->restore($auth, 'yes'); self::fail('Implicit restore accepted'); }
            catch (RehearsalFailure $failure) { self::assertSame('explicit_production_restore_required', $failure->reason); }
            // Close extra privileged test connection before exact restore boundary check.
            $guard->restore($auth, 'RESTORE PRODUCTION ' . $digest);
            self::assertSame($before, $target->fingerprint());
            self::assertNotFalse($primary->query("DROP TABLE `{$primary->options}`"));
            // A previous native restore may have failed partway through DDL. Recovery cannot need healthy options/users.
            $guard->restore($auth, 'RESTORE PRODUCTION ' . $digest);
            self::assertSame($before, $target->fingerprint());
            self::assertSame('1', $primary->get_var('SELECT @@GLOBAL.read_only'));
            self::assertNotFalse($primary->query("DROP DATABASE `{$primaryName}`"));
            self::assertNotFalse($primary->query("DROP DATABASE `{$probeName}`"));
            $primary->close();
            // Cold recovery connection: even the selected database may be absent.
            $primary = new \wpdb('root', 'root', 'information_schema', 'db');
            $primary->set_prefix($this->database->prefix);
            $primary->suppress_errors(true);
            $recoveryTarget = new WpdbProductionMigrationTarget($primary, $app->personalMigrationTargets(), $environment, $user, $library, $identity, $directory . '/web/.maintenance');
            $recoveryTarget->prepareRestore($auth);
            $recoveryBackups = new ProductionBackupDirectory($directory . '/backups', $recoveryTarget,
                new MariaDbProductionTransport($primary, $primaryName, 'db', 'root', 'root', $recoveryTarget, false, $auth),
                null, $evidence);
            (new \Biblio\Core\Application\Migration\Cutover\ProductionRollback($recoveryTarget, $recoveryBackups, $evidence))->restore($auth, 'RESTORE PRODUCTION ' . $digest);
            self::assertSame($before, $recoveryTarget->fingerprint());
            self::assertFileExists($directory . '/web/.maintenance');
            foreach (glob($directory . '/evidence/*/*.json') ?: [] as $path) {
                self::assertStringNotContainsString('synthetic-private', (string) file_get_contents($path));
            }
        } finally {
            // Explicit test cleanup, positively scoped to this disposable server only.
            self::assertMatchesRegularExpression('/^biblio-v2-cutover-[a-f0-9]{12}$/D', (string) getenv('DDEV_SITENAME'));
            $admin = new \wpdb('root', 'root', 'biblio_core_test', 'db');
            $admin->query('SET GLOBAL read_only=OFF');
            $GLOBALS['wpdb'] = $originalGlobal;
            wp_cache_flush();
            foreach ([$primaryName, $probeName] as $name) {
                $admin->query("DROP DATABASE IF EXISTS `{$name}`");
                $admin->query("REVOKE ALL ON `{$name}`.* FROM 'db'@'%'");
            }
            if ($userId > 0) { $this->database->delete($this->database->usermeta, ['user_id' => $userId]); $this->database->delete($this->database->users, ['ID' => $userId]); }
            foreach ([$primary ?? null, $probe ?? null, $ordinary ?? null, $fresh ?? null, $admin] as $connection) { if ($connection instanceof \wpdb) { $connection->close(); } }
            putenv($flag === false ? 'BIBLIO_PRODUCTION_TEST' : 'BIBLIO_PRODUCTION_TEST=' . $flag);
            RehearsalFixture::remove($directory);
        }
    }
}
