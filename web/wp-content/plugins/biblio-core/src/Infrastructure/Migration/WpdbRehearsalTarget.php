<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Identity\MigrationTargetValidator;
use Biblio\Core\Application\Migration\Cutover\{RehearsalContract, RehearsalEnvironmentIdentity, RehearsalTarget};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\WordPress\Migration\RuntimeMigrationEnvironment;
use wpdb;

final class WpdbRehearsalTarget implements RehearsalTarget
{
    private bool $locked = false;
    private readonly RehearsalDatabaseState $state;

    /** @param array<string,mixed> $acceptedIdentity */
    public function __construct(
        private readonly wpdb $db,
        private readonly MigrationTargetValidator $targets,
        private readonly string $repositoryRoot,
        private readonly UserId $user,
        private readonly LibraryId $library,
        private readonly array $acceptedIdentity
    ) {
        $this->state = new RehearsalDatabaseState($db, new CoreTableNames($db->prefix));
    }

    public function identity(): array
    {
        $actual = $this->observedIdentity();
        RehearsalEnvironmentIdentity::assert($actual, $this->acceptedIdentity);
        return $actual;
    }

    /**
     * Read-only runtime inventory for a separately reviewed target specification.
     * @return array<string,mixed>
     */
    public function observedIdentity(): array
    {
        $plugin = $this->repositoryRoot . "/web/wp-content/plugins/biblio-core/biblio-core.php";
        $environment = new RuntimeMigrationEnvironment($plugin);
        $environment->assertHealthy();
        $build = $environment->provenance()->toArray();
        $database = $this->db->get_var("SELECT DATABASE()");
        $project = getenv("DDEV_SITENAME");
        // Reject before querying the marker or invoking target services in normal development.
        RehearsalContract::require(is_string($database) && preg_match('/^biblio_cutover_[a-f0-9]{12}$/D', $database) === 1
            && is_string($project) && preg_match('/^biblio-v2-cutover-[a-f0-9]{12}$/D', $project) === 1
            && getenv("BIBLIO_REHEARSAL") === "1"
            && getenv("DB_NAME") === $database, "production_or_unknown_target");
        $marker = $this->db->get_row("SELECT purpose,environment_id,project,database_name AS `database`,git_sha FROM biblio_cutover_guard WHERE singleton=1", ARRAY_A);
        RehearsalContract::require(is_array($marker), "positive_rehearsal_marker_missing");
        $this->targets->validate($this->user, $this->library);
        $user = get_userdata((int) $this->user->value());
        RehearsalContract::require($user !== false, "target_user_missing");
        $roles = array_values($user->roles);
        sort($roles, SORT_STRING);
        $extensions = get_loaded_extensions();
        sort($extensions, SORT_STRING);
        $uiBytes = file_get_contents($this->repositoryRoot . "/web/wp-content/plugins/biblio-ui/biblio-ui.php");
        RehearsalContract::require(is_string($uiBytes) && preg_match('/^ \* Version:\s*([^\r\n]+)$/m', $uiBytes, $ui) === 1, "ui_version_missing");
        $actual = [
            "environment_id" => getenv("BIBLIO_REHEARSAL_ID"),
            "project" => $project, "database" => $database,
            "root" => realpath($this->repositoryRoot),
            "git_sha" => $build["git_revision"], "dirty" => $build["working_tree_dirty"],
            "runtime" => [
                "wordpress" => get_bloginfo("version"), "php" => PHP_VERSION,
                "mariadb" => $this->db->get_var("SELECT VERSION()"),
                "charset" => $this->db->get_var("SELECT @@character_set_database"),
                "collation" => $this->db->get_var("SELECT @@collation_database"),
                "sql_mode" => $this->db->get_var("SELECT @@SESSION.sql_mode"),
                "extensions" => $extensions, "product" => $build["product_version"],
                "core" => $build["core_version"], "ui" => trim($ui[1]),
                "schema" => $build["schema_version"], "ddev" => getenv("DDEV_VERSION"),
                "dependency_sha256" => hash_file("sha256", $this->repositoryRoot . "/web/wp-content/plugins/biblio-core/composer.lock"),
            ],
            "marker" => $marker,
            "target_user_id" => $this->user->value(), "target_library_id" => $this->library->value(),
            "roles" => $roles, "super_admin" => is_super_admin((int) $this->user->value()),
            "environment_type" => wp_get_environment_type(),
            "writes_frozen" => defined("DISABLE_WP_CRON") && constant("DISABLE_WP_CRON") === true
                && getenv("BIBLIO_REHEARSAL_WRITES_FROZEN") === "1"
                && is_file($this->repositoryRoot . "/web/.maintenance")
                && trim((string) file_get_contents($this->repositoryRoot . "/web/.maintenance")) === '<?php $upgrading = time();',
        ];
        return $actual;
    }

    public function fingerprint(): array { return $this->state->fingerprint(); }
    public function assertEmpty(): void
    {
        $this->identity();
        $this->state->assertEmpty($this->user->value(), $this->library->value());
    }

    public function acquire(): void
    {
        $identity = $this->identity();
        RehearsalContract::require(!$this->locked && $this->db->get_var($this->db->prepare(
            "SELECT GET_LOCK(%s,0)", "biblio-cutover-" . $identity["environment_id"]
        )) === "1", "rehearsal_already_running");
        $this->locked = true;
        add_filter("pre_http_request", [$this, "denyNetwork"], PHP_INT_MIN);
    }

    public function release(): void
    {
        if ($this->locked) {
            $this->db->get_var($this->db->prepare("SELECT RELEASE_LOCK(%s)", "biblio-cutover-" . $this->acceptedIdentity["environment_id"]));
            $this->locked = false;
            remove_filter("pre_http_request", [$this, "denyNetwork"], PHP_INT_MIN);
        }
    }

    public function denyNetwork(): never
    {
        throw new \Biblio\Core\Application\Migration\Cutover\RehearsalFailure("provider_request_forbidden");
    }
}
