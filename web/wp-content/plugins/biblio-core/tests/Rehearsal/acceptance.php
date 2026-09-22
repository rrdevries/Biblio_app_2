<?php

declare(strict_types=1);

// Invoked only by the no-arguments synthetic acceptance shell, never a public WP command.
require_once dirname(__DIR__, 2) . "/vendor/autoload.php";

use Biblio\Core\Application\Migration\Cutover\{RehearsalAuthorization, RehearsalContract, RehearsalFault};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\{MariaDbRehearsalTransport, RehearsalBackupDirectory, RehearsalComposition, RehearsalEvidenceDirectory, WpdbRehearsalIsolationGuard, WpdbRehearsalTarget};
use Biblio\Core\Infrastructure\WordPress\Migration\RuntimeMigrationEnvironment;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Tests\Support\RehearsalFixture;

RehearsalContract::require(PHP_SAPI === "cli" && getenv("BIBLIO_REHEARSAL") === "1"
    && preg_match('/^biblio-v2-cutover-[a-f0-9]{12}$/D', (string) getenv("DDEV_SITENAME")) === 1
    && preg_match('/^biblio_cutover_[a-f0-9]{12}$/D', (string) getenv("DB_NAME")) === 1
    && preg_match('/^biblio_cutover_[a-f0-9]{12}$/D', (string) getenv("BIBLIO_REHEARSAL_PROBE")) === 1,
    "synthetic_acceptance_environment_required");
RehearsalContract::require(defined("WP_CLI") && WP_CLI, "wp_cli_test_host_required");
require_once dirname(__DIR__, 5) . "/wp-load.php";
require_once dirname(__DIR__) . "/Support/RehearsalFixture.php";
global $wpdb;
$wpdb->suppress_errors(true);
$repo = dirname(__DIR__, 6);
$runtime = new RuntimeMigrationEnvironment(dirname(__DIR__, 2) . "/biblio-core.php");
$build = $runtime->provenance()->toArray();
RehearsalContract::require($build["working_tree_dirty"] === false && $build["git_revision"] !== null, "clean_candidate_required");
$fault = RehearsalFault::from((string) getenv("BIBLIO_REHEARSAL_FAULT"));
$project = (string) getenv("DDEV_SITENAME");
$database = (string) getenv("DB_NAME");
$probeName = (string) getenv("BIBLIO_REHEARSAL_PROBE");
$root = $repo . "/.local/evidence/" . $fault->value;
RehearsalContract::require(mkdir($root, 0700), "synthetic_attempt_exists");
foreach (["backups", "receipts"] as $dir) { mkdir($root . "/" . $dir, 0700); }
$evidence = new RehearsalEvidenceDirectory($root . "/receipts");
$marker = ["purpose" => "MIG-CUTOVER-PREP-01B", "environment_id" => (string) getenv("BIBLIO_REHEARSAL_ID"),
    "project" => $project, "database" => $database, "git_sha" => $build["git_revision"]];
$probe = new wpdb(DB_USER, DB_PASSWORD, $probeName, DB_HOST);
$probe->set_prefix($wpdb->prefix);
$probe->suppress_errors(true);
RehearsalContract::require($database !== $probeName
    && $wpdb->get_var("SELECT DATABASE()") === $database
    && $probe->get_var("SELECT DATABASE()") === $probeName, "synthetic_connected_database_mismatch");
foreach ([$wpdb, $probe] as $db) {
    RehearsalContract::require($db->query("CREATE TABLE biblio_cutover_guard (singleton INT PRIMARY KEY,purpose VARCHAR(64),environment_id VARCHAR(64),project VARCHAR(64),database_name VARCHAR(64),git_sha CHAR(40))") !== false, "marker_provision_failed");
    RehearsalContract::require($db->insert("biblio_cutover_guard", ["singleton" => 1, "purpose" => $marker["purpose"], "environment_id" => $marker["environment_id"],
        "project" => $project, "database_name" => $database, "git_sha" => $marker["git_sha"]]) !== false, "marker_provision_failed");
}
$id = wp_create_user("synthetic_target", wp_generate_password(32), "target@example.invalid");
RehearsalContract::require(is_int($id), "synthetic_user_creation_failed");
(new WP_User($id))->set_role("subscriber");
$user = new UserId((string) $id);
$app = (new ProductionComposition($wpdb))->application();
$library = $app->personalMigrationTargets()->bootstrap($user)->libraryId();
$inventory = (new WpdbRehearsalTarget($wpdb, $app->personalMigrationTargets(), $repo, $user, $library, []))->observedIdentity();
// Explicit TEST approval fixture, never an automatic approval for a supplied source.
$target = new WpdbRehearsalTarget($wpdb, $app->personalMigrationTargets(), $repo, $user, $library, $inventory);
$normal = new wpdb(DB_USER, DB_PASSWORD, "db", "ddev-biblio-v2-db");
$normal->set_prefix($wpdb->prefix);
$normal->suppress_errors(true);
$isolation = new WpdbRehearsalIsolationGuard($normal, $wpdb);
$protectedBefore = $isolation->fingerprint();
$source = RehearsalFixture::source($root, true);
$backups = new RehearsalBackupDirectory($root . "/backups", $target,
    new MariaDbRehearsalTransport($wpdb, $database, DB_HOST, DB_USER, DB_PASSWORD, $marker, $project),
    new MariaDbRehearsalTransport($probe, $probeName, DB_HOST, DB_USER, DB_PASSWORD, $marker, $project), $evidence);
$rehearsal = (new RehearsalComposition($wpdb, $app, $runtime))->create($source, $target, $backups, $evidence, $isolation, $user, $library);
$packet = $rehearsal->preflight();
$authorization = new RehearsalAuthorization($packet["preflight"], $packet["pre_apply_backup"], "explicit-synthetic-fixture-approval",
    RehearsalAuthorization::confirmationFor($packet["preflight"], $packet["pre_apply_backup"], true, $fault), true, $fault);
$result = $rehearsal->execute($authorization);
if ($fault !== RehearsalFault::None) {
    RehearsalContract::require($result["checkpoint"]["status"] === "interrupted", "expected_interruption_missing");
    $result = $rehearsal->execute($authorization, "resume", $result["checkpoint"]);
}
RehearsalContract::require($result["checkpoint"]["status"] === "completed" && $result["classification"] === "accepted_with_reviewed_quarantine", "synthetic_apply_incomplete");
$replay = $rehearsal->execute($authorization, "replay", $result["checkpoint"]);
RehearsalContract::require($replay["replay_zero_state_delta"] === true, "synthetic_replay_changed_state");
$rehearsal->rollback($authorization);
RehearsalContract::equal($target->fingerprint(), $packet["preflight"]["baseline"], "synthetic_rollback_changed_state");
RehearsalContract::equal($isolation->fingerprint(), $protectedBefore, "normal_database_changed");
$receipt = $evidence->append("prep01b-synthetic-acceptance", ["build" => $build, "environment" => $inventory,
    "candidate" => $source->provenance(), "fault" => $fault->value, "apply_artifact" => $result["artifact"], "replay_artifact" => $replay["artifact"],
    "backup_restore" => "independent_native_verified", "rollback" => "exact_baseline", "normal_database_unchanged" => true,
    "candidate_source_unchanged" => true, "final_rehearsal" => false, "production_apply_authorized" => false]);
echo json_encode(["synthetic_prep01b" => "passed", "fault" => $fault->value, "artifact" => $receipt], JSON_THROW_ON_ERROR) . "\n";
