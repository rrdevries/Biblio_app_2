<?php

declare(strict_types=1);

use Biblio\Core\Application\Migration\Cutover\{ProductionTestResetAuthorization, RehearsalContract, RehearsalFailure};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Infrastructure\Migration\{MariaDbProductionTransport, ProductionApplicationConfiguration, ProductionBackupDirectory, ProductionEvidenceDirectory, WpdbProductionMigrationTarget, WpdbProductionTestTargetReset};
use Biblio\Core\Infrastructure\WordPress\Migration\RuntimeMigrationEnvironment;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;

/** No default mode, source loader, migration planner, FINAL approval or arbitrary-table reset. */
function resetJson(string $path): array
{
    RehearsalContract::require(is_file($path) && !is_link($path) && realpath($path) === $path
        && (fileperms($path) & 0077) === 0, 'test_reset_input_unsafe');
    $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    RehearsalContract::require(is_array($value), 'test_reset_input_invalid');
    return $value;
}

$mode = $argv[1] ?? '';
if (!in_array($mode, ['prepare', 'test-reset', 'test-reset-restore'], true)) {
    fwrite(STDERR, "Usage: php scripts/migration-test-target-reset.php prepare CONFIG.json\n       php scripts/migration-test-target-reset.php test-reset CONFIG.json PACKET.json APPROVAL.json\n       php scripts/migration-test-target-reset.php test-reset-restore CONFIG.json PACKET.json APPROVAL.json 'RESTORE TEST TARGET <packet-digest>'\n");
    exit(2);
}
require dirname(__DIR__) . '/web/wp-content/plugins/biblio-core/vendor/autoload.php';
try {
    RehearsalContract::require(PHP_SAPI === 'cli' && getenv('DDEV_SITENAME') === 'biblio-v2'
        && getenv('DDEV_APPROOT') === '/var/www/html' && getenv('BIBLIO_PRODUCTION_TEST') !== '1'
        && (getenv('DB_NAME') === false || getenv('DB_NAME') === 'db')
        && (getenv('DB_USER') === false || getenv('DB_USER') === 'db')
        && (getenv('DB_HOST') === false || getenv('DB_HOST') === 'db'), 'production_cli_environment_forbidden');
    RehearsalContract::require($argc === ($mode === 'prepare' ? 3 : ($mode === 'test-reset' ? 5 : 6)), 'test_reset_arguments_invalid');
    ProductionApplicationConfiguration::assertAudited(dirname(__DIR__));
    $config = resetJson($argv[2]);
    RehearsalContract::require(($config['target']['purpose'] ?? null) === 'production', 'production_configuration_required');
    $authorization = $mode === 'prepare' ? null : new ProductionTestResetAuthorization(resetJson($argv[3]), resetJson($argv[4]));
    if ($mode === 'test-reset-restore') { $authorization->assertRestoreConfirmation($argv[5]); }
    $productionDatabase = $mode === 'test-reset-restore' ? 'information_schema' : 'db';
    require __DIR__ . '/production-cutover-bootstrap.php';
    $app = (new ProductionComposition($wpdb))->application();
    $target = new WpdbProductionMigrationTarget($wpdb, $app->personalMigrationTargets(),
        new RuntimeMigrationEnvironment(dirname(__DIR__) . '/web/wp-content/plugins/biblio-core/biblio-core.php'),
        new UserId($config['target']['target_user_id']), new LibraryId($config['target']['target_library_id']),
        $config['target'], dirname(__DIR__) . '/web/.maintenance');
    if ($mode === 'test-reset-restore') { $target->prepareTestResetRestore($authorization); }
    $target->identity();
    foreach (['evidence_root', 'backup_root'] as $key) {
        RehearsalContract::require(is_string($config[$key]) && str_starts_with($config[$key], '/var/www/html/.local/')
            && realpath($config[$key]) === $config[$key], 'production_output_location_forbidden');
    }
    $evidence = new ProductionEvidenceDirectory($config['evidence_root']);
    $probeTransport = null;
    if ($mode === 'prepare') {
        $reviewed = resetJson($config['reviewed_snapshot']);
        RehearsalContract::equal(hash_file('sha256', $config['reviewed_snapshot']), $config['reviewed_snapshot_sha256'], 'reviewed_snapshot_changed');
        $reviewedTables = [];
        foreach ($reviewed['watched_state']['tables'] as $name => $state) {
            $reviewedTables[$name] = ['rows' => $state['rows'], 'schema_sha256' => $state['schema_sha256'], 'data_sha256' => $state['content_sha256']];
        }
        // Reject stale/unreviewed normal data before even creating the disposable probe.
        RehearsalContract::equal($target->fingerprint()['tables'], $reviewedTables, 'reviewed_test_population_changed');
        $probeName = $config['restore_probe_database'];
        RehearsalContract::require(is_string($probeName) && preg_match('/^biblio_prod_probe_[a-f0-9]{12}$/D', $probeName) === 1, 'production_probe_forbidden');
        RehearsalContract::require($wpdb->query("CREATE DATABASE `{$probeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci") !== false, 'production_probe_already_exists');
        $probe = new wpdb('root', 'root', $probeName, 'db');
        $probe->set_prefix($wpdb->prefix);
        $probe->suppress_errors(true);
        $probeTransport = new MariaDbProductionTransport($probe, $probeName, 'db', 'root', 'root', $target, true);
    }
    $backups = new ProductionBackupDirectory($config['backup_root'], $target,
        new MariaDbProductionTransport($wpdb, 'db', 'db', 'root', 'root', $target, false,
            $mode === 'test-reset-restore' ? $authorization : null), $probeTransport, $evidence);
    $reset = new WpdbProductionTestTargetReset($wpdb, $target, $backups, $evidence);
    if ($mode === 'prepare') {
        $packet = $reset->prepare($reviewedTables, $config['scope']);
        $receipt = $evidence->append('production-test-reset-packet', $packet);
        echo json_encode(['status' => 'PRE_RESET_VERIFIED_NO_NORMAL_WRITES', 'packet' => $packet,
            'packet_digest' => ProductionTestResetAuthorization::digest($packet), 'artifact' => $receipt], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
    } elseif ($mode === 'test-reset') {
        echo json_encode($reset->execute($authorization), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
    } else {
        $reset->restore($authorization, $argv[5]);
        echo "{\"status\":\"PRE_RESET_RESTORED_WRITES_REMAIN_BLOCKED\"}\n";
    }
} catch (Throwable $failure) {
    fwrite(STDERR, json_encode(['status' => 'HOLD', 'reason' => $failure instanceof RehearsalFailure ? $failure->reason : 'test_reset_failed'], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
