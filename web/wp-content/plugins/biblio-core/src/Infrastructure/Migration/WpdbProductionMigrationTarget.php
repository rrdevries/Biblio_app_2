<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Identity\MigrationTargetValidator;
use Biblio\Core\Application\Migration\Cutover\{ProductionAuthorization, ProductionMigrationTarget, RehearsalContract, RehearsalFailure};
use Biblio\Core\Application\Migration\Runner\MigrationEnvironment;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

/** Positive normal-site identity. Test simulations are explicit and cannot select normal db. */
final class WpdbProductionMigrationTarget implements ProductionMigrationTarget
{
    private bool $locked = false;
    private bool $restoring = false;
    private readonly RehearsalDatabaseState $state;
    /** @param array<string,mixed> $expected */
    public function __construct(private readonly wpdb $db, private readonly MigrationTargetValidator $targets,
        private readonly MigrationEnvironment $environment, private readonly UserId $user, private readonly LibraryId $library,
        private readonly array $expected, private readonly string $maintenancePath)
    {
        $this->state = new RehearsalDatabaseState($db, new CoreTableNames($db->prefix));
    }

    /** @param array<string,mixed> $expected */
    public static function assertLocation(array $expected, string $project, string $database, string $root, string $url): void
    {
        $production = ($expected["purpose"] ?? null) === "production" && $project === "biblio-v2" && $database === "db"
            && $root === "/var/www/html" && $url === "https://biblio-v2.ddev.site";
        $simulation = ($expected["purpose"] ?? null) === "production-simulation"
            && preg_match('/^biblio-v2-cutover-[a-f0-9]{12}$/D', $project) === 1
            && preg_match('/^biblio_prod_test_[a-f0-9]{12}$/D', $database) === 1
            && getenv("BIBLIO_PRODUCTION_TEST") === "1";
        RehearsalContract::require($production || $simulation, "production_target_forbidden");
        foreach (["project" => $project, "database" => $database, "root" => $root, "url" => $url] as $key => $value) {
            RehearsalContract::equal($expected[$key] ?? null, $value, "production_identity_changed");
        }
    }

    public function identity(): array
    {
        $selected = $this->db->get_var("SELECT DATABASE()");
        RehearsalContract::require($selected === $this->expected["database"] || ($selected === "information_schema" && $this->restoring), "production_database_changed");
        self::assertLocation($this->expected, (string) getenv("DDEV_SITENAME"), $this->expected["database"],
            (string) realpath(dirname($this->maintenancePath, 2)), $this->restoring ? $this->expected["url"] : (string) $this->db->get_var("SELECT option_value FROM `{$this->db->options}` WHERE option_name='siteurl'"));
        if (!$this->restoring) { $this->environment->assertHealthy(); }
        $build = $this->environment->provenance()->toArray();
        RehearsalContract::require($build["working_tree_dirty"] === false, "production_dirty_build");
        RehearsalContract::equal($build["git_revision"], $this->expected["git_sha"] ?? null, "production_candidate_changed");
        if (!$this->restoring) {
            $this->targets->validate($this->user, $this->library);
            $user = get_userdata((int) $this->user->value());
            RehearsalContract::require($user !== false && array_values($user->roles) === ["subscriber"] && !is_super_admin((int) $this->user->value()), "ordinary_target_user_required");
        }
        RehearsalContract::equal($this->user->value(), $this->expected["target_user_id"] ?? null, "production_user_changed");
        RehearsalContract::equal($this->library->value(), $this->expected["target_library_id"] ?? null, "production_library_changed");
        return $this->expected + ["build" => $build, "server_version" => $this->db->get_var("SELECT VERSION()"), "prefix" => $this->db->prefix];
    }

    public function fingerprint(): array { $this->identity(); return $this->state->fingerprint(); }
    public function assertEmpty(): void { $this->identity(); $this->state->assertEmpty($this->user->value(), $this->library->value()); }
    public function acquire(): void
    {
        $this->identity();
        RehearsalContract::require(!$this->locked && $this->db->get_var("SELECT GET_LOCK('biblio-production-cutover',0)") === "1", "production_already_running");
        $this->locked = true;
        add_filter("pre_http_request", [$this, "denyNetwork"], PHP_INT_MIN);
    }
    public function release(): void
    {
        if ($this->locked) {
            $this->db->get_var("SELECT RELEASE_LOCK('biblio-production-cutover')");
            $this->locked = false;
            remove_filter("pre_http_request", [$this, "denyNetwork"], PHP_INT_MIN);
        }
        // Intentionally never restore read_only or remove maintenance automatically.
    }
    public function denyNetwork(): never { throw new RehearsalFailure("provider_request_forbidden"); }

    public function prepareRestore(ProductionAuthorization $authorization): void
    {
        $this->restoring = true;
        RehearsalContract::equal($this->identity(), $authorization->packet["binding"]["environment"], "production_restore_identity_changed");
    }

    public function prepareTestResetRestore(\Biblio\Core\Application\Migration\Cutover\ProductionTestResetAuthorization $authorization): void
    {
        $this->restoring = true;
        RehearsalContract::equal($this->identity(), $authorization->packet["binding"]["environment"], "test_reset_restore_identity_changed");
    }

    public function blockWrites(): void
    {
        $this->identity();
        RehearsalContract::require($this->locked, "production_lock_required");
        $this->assertWriterBoundary();
        $bytes = '<?php $upgrading = time();';
        if (file_exists($this->maintenancePath)) {
            RehearsalContract::require(!is_link($this->maintenancePath) && file_get_contents($this->maintenancePath) === $bytes, "unknown_maintenance_file");
        } else {
            $file = fopen($this->maintenancePath, "xb");
            RehearsalContract::require(is_resource($file), "production_maintenance_failed");
            try { RehearsalContract::require(fwrite($file, $bytes) === strlen($bytes) && fflush($file), "production_maintenance_failed"); }
            finally { fclose($file); }
        }
        RehearsalContract::require($this->db->query("SET SESSION lock_wait_timeout=5") !== false
            && $this->db->query("SET GLOBAL read_only=ON") !== false, "production_write_block_failed");
        // Explicit table-list FLUSH waits for existing transactions' metadata
        // locks. INNODB_TRX is sampled/cached and cannot prove quiescence.
        // read_only is already ON, so no new ordinary writer can enter.
        $names = $this->db->get_col($this->db->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME", $this->expected["database"]));
        RehearsalContract::require($this->db->last_error === "" && ($names !== [] || $this->restoring), "production_table_inventory_failed");
        $tables = [];
        foreach ($names as $name) {
            RehearsalContract::require(preg_match('/^[a-zA-Z0-9_]+$/D', $name) === 1, "production_table_name_invalid");
            $tables[] = '`' . $this->expected["database"] . '`.`' . $name . '`';
        }
        if ($tables !== []) {
            try {
                RehearsalContract::require($this->db->query("FLUSH TABLES " . implode(',', $tables) . " WITH READ LOCK") !== false, "production_writer_drain_failed");
            } finally { $this->db->query("UNLOCK TABLES"); }
        }
        $this->assertWritesBlocked();
    }

    public function assertWritesBlocked(): void
    {
        RehearsalContract::require($this->locked && $this->db->get_var("SELECT IS_USED_LOCK('biblio-production-cutover')=CONNECTION_ID()") === "1"
            && $this->db->get_var("SELECT @@GLOBAL.read_only") === "1"
            && is_file($this->maintenancePath) && !is_link($this->maintenancePath)
            && file_get_contents($this->maintenancePath) === '<?php $upgrading = time();', "production_write_block_lost");
    }

    /** No grants or process details leave this method. The normal WP account must be unprivileged. */
    private function assertWriterBoundary(): void
    {
        RehearsalContract::require($this->db->get_var("SELECT SUBSTRING_INDEX(CURRENT_USER(),'@',1)") === "root", "production_operator_connection_required");
        $global = $this->db->get_results("SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE LIKE '\'db\'@%'", ARRAY_A);
        RehearsalContract::require(is_array($global) && $this->db->last_error === "", "production_privileges_unreadable");
        foreach ($global as $row) {
            RehearsalContract::require($row["PRIVILEGE_TYPE"] === "USAGE", "production_app_privilege_unsafe");
        }
        RehearsalContract::require($this->db->get_var("SELECT COUNT(*) FROM mysql.roles_mapping WHERE User='db'") === "0", "production_app_roles_unsafe");
        RehearsalContract::require($this->db->get_var("SELECT @@GLOBAL.event_scheduler") === "OFF", "production_scheduler_active");
        $replicas = $this->db->get_results("SHOW ALL SLAVES STATUS", ARRAY_A);
        RehearsalContract::require($replicas === [] && $this->db->last_error === "", "production_replication_active");
        RehearsalContract::require($this->db->get_var("SELECT @@GLOBAL.wsrep_on") === "0", "production_cluster_active");
        RehearsalContract::require($this->db->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() AND USER NOT IN ('db','system user')") === "0", "production_other_operator_active");
    }
}
