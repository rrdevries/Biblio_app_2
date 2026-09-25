<?php

declare(strict_types=1);

use Biblio\Core\Application\Migration\Cutover\{ProductionAuthorization, ProductionRollback, RehearsalContract, RehearsalFailure};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Infrastructure\Migration\{MariaDbProductionTransport, ProductionApplicationConfiguration, ProductionBackupDirectory, ProductionCutoverComposition, ProductionEvidenceDirectory, ProductionSourceLoader, WpdbProductionMigrationTarget};
use Biblio\Core\Infrastructure\WordPress\Migration\RuntimeMigrationEnvironment;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;

/** Exact operational paths only; no default operation and no authorization generator. */
function productionJson(string $path): array
{
    RehearsalContract::require(is_file($path) && !is_link($path) && (fileperms($path) & 0077) === 0, 'production_input_unsafe');
    $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    RehearsalContract::require(is_array($value), 'production_input_invalid');
    return $value;
}

$mode = $argv[1] ?? '';
if (!in_array($mode, ['preflight', 'apply', 'restore'], true)) {
    fwrite(STDERR, "Usage: php scripts/migration-production.php preflight CONFIG.json\n       php scripts/migration-production.php apply CONFIG.json PACKET.json APPROVAL.json\n       php scripts/migration-production.php restore CONFIG.json PACKET.json APPROVAL.json 'RESTORE PRODUCTION <packet-digest>'\nNo default action. Preflight never authorizes apply.\n");
    exit(2);
}
require dirname(__DIR__) . '/web/wp-content/plugins/biblio-core/vendor/autoload.php';
try {
    RehearsalContract::require(PHP_SAPI === 'cli' && getenv('DDEV_SITENAME') === 'biblio-v2'
        && getenv('DDEV_APPROOT') === '/var/www/html' && getenv('BIBLIO_PRODUCTION_TEST') !== '1'
        && (getenv('DB_NAME') === false || getenv('DB_NAME') === 'db')
        && (getenv('DB_USER') === false || getenv('DB_USER') === 'db')
        && (getenv('DB_HOST') === false || getenv('DB_HOST') === 'db'), 'production_cli_environment_forbidden');
    RehearsalContract::require($argc === ($mode === 'preflight' ? 3 : ($mode === 'apply' ? 5 : 6)), 'production_arguments_invalid');
    ProductionApplicationConfiguration::assertAudited(dirname(__DIR__));
    $config = productionJson($argv[2]);
    RehearsalContract::require(($config['target']['purpose'] ?? null) === 'production', 'production_configuration_required');
    $authorization = $mode === 'preflight' ? null : new ProductionAuthorization(productionJson($argv[3]), productionJson($argv[4]));
    if ($mode === 'restore') { $authorization->assertRestoreConfirmation($argv[5]); }
    $productionDatabase = $mode === 'restore' ? 'information_schema' : 'db';
    require __DIR__ . '/production-cutover-bootstrap.php';
    $environment = new RuntimeMigrationEnvironment(dirname(__DIR__) . '/web/wp-content/plugins/biblio-core/biblio-core.php');
    $app = (new ProductionComposition($wpdb))->application();
    $user = new UserId($config['target']['target_user_id']);
    $library = new LibraryId($config['target']['target_library_id']);
    $target = new WpdbProductionMigrationTarget($wpdb, $app->personalMigrationTargets(), $environment, $user, $library,
        $config['target'], dirname(__DIR__) . '/web/.maintenance');
    if ($mode === 'restore') { $target->prepareRestore($authorization); }
    $target->identity();
    foreach (['evidence_root', 'backup_root'] as $key) {
        RehearsalContract::require(is_string($config[$key]) && str_starts_with($config[$key], '/var/www/html/.local/')
            && realpath($config[$key]) === $config[$key], 'production_output_location_forbidden');
    }
    $evidence = new ProductionEvidenceDirectory($config['evidence_root']);
    $source = $mode === 'restore' ? null : ProductionSourceLoader::load($config['source']);
    // A new independently named disposable restore probe is permitted, never a normal DB restore in preflight.
    $probeName = $config['restore_probe_database'];
    RehearsalContract::require(preg_match('/^biblio_prod_probe_[a-f0-9]{12}$/D', $probeName) === 1, 'production_probe_forbidden');
    if ($mode === 'preflight') {
        RehearsalContract::require($wpdb->query("CREATE DATABASE `{$probeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci") !== false, 'production_probe_already_exists');
    }
    $probeTransport = null;
    if ($mode === 'preflight') {
        $probe = new wpdb('root', 'root', $probeName, 'db');
        $probe->set_prefix($wpdb->prefix);
        $probe->suppress_errors(true);
        $probeTransport = new MariaDbProductionTransport($probe, $probeName, 'db', 'root', 'root', $target, true);
    }
    $backups = new ProductionBackupDirectory($config['backup_root'], $target,
        new MariaDbProductionTransport($wpdb, 'db', 'db', 'root', 'root', $target, false, $mode === 'restore' ? $authorization : null),
        $probeTransport, $evidence);
    if ($mode === 'restore') {
        (new ProductionRollback($target, $backups, $evidence))->restore($authorization, $argv[5]);
        echo "{\"status\":\"PRE_RESTORED_WRITES_REMAIN_BLOCKED\"}\n";
        exit(0);
    }
    $cutover = (new ProductionCutoverComposition($wpdb, $app, $environment))->create($source, $target, $backups, $evidence, $user, $library);
    if ($mode === 'preflight') {
        $packet = $cutover->preflight();
        $receipt = $evidence->append('production-authorization-packet', $packet);
        echo json_encode(['status' => 'PREFLIGHT_ONLY', 'packet' => $packet, 'packet_digest' => ProductionAuthorization::digest($packet), 'receipt' => $receipt,
            'production_authorization_created' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
    } elseif ($mode === 'apply') {
        echo json_encode($cutover->execute($authorization), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
    }
} catch (Throwable $failure) {
    fwrite(STDERR, json_encode(['status' => 'HOLD', 'reason' => $failure instanceof RehearsalFailure ? $failure->reason : 'production_preparation_or_execution_failed'], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
