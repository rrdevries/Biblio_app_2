<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Migration\Cutover\{RehearsalFailure, RehearsalTarget};
use Biblio\Core\Infrastructure\Migration\{MariaDbRehearsalTransport, RehearsalBackupDirectory, RehearsalDatabaseState, RehearsalEvidenceDirectory};
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Tests\Support\RehearsalFixture;

require_once dirname(__DIR__) . "/Support/RehearsalFixture.php";

/** Native transport proof; only this test injects a synthetic process project identity. */
final class RehearsalNativeBackupTest extends PersistenceIntegrationTestCase
{
    public function testNativeDumpIndependentRestorePostBackupAndExactRollback(): void
    {
        self::assertSame("biblio_core_test", DB_NAME);
        self::assertTrue(getenv("DDEV_SITENAME") === "biblio-v2"
            || (preg_match('/^biblio-v2-cutover-[a-f0-9]{12}$/D', (string) getenv("DDEV_SITENAME")) === 1
                && getenv("BIBLIO_REHEARSAL") === "1"));
        $projectBefore = getenv("DDEV_SITENAME");
        $flagBefore = getenv("BIBLIO_REHEARSAL");
        $primaryName = "biblio_cutover_" . bin2hex(random_bytes(6));
        $probeName = "biblio_cutover_" . bin2hex(random_bytes(6));
        $project = "biblio-v2-cutover-" . bin2hex(random_bytes(6));
        $directory = sys_get_temp_dir() . "/biblio-native-backup-test-" . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory . "/backups", 0700);
        mkdir($directory . "/evidence", 0700);
        // DDEV's local disposable-test credentials; never output or serialize them.
        $admin = new \wpdb("root", "root", "biblio_core_test", "db");
        $admin->suppress_errors(true);
        self::assertSame("biblio_core_test", $admin->get_var("SELECT DATABASE()"));
        $marker = ["purpose" => "MIG-CUTOVER-PREP-01B", "environment_id" => "synthetic-native-backup",
            "project" => $project, "database" => $primaryName, "git_sha" => str_repeat("a", 40)];
        try {
            foreach ([$primaryName, $probeName] as $database) {
                self::assertSame(1, preg_match('/^biblio_cutover_[a-f0-9]{12}$/D', $database));
                self::assertNotFalse($admin->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"));
            }
            $primary = new \wpdb("root", "root", $primaryName, "db");
            $probe = new \wpdb("root", "root", $probeName, "db");
            foreach ([$primary, $probe] as $db) {
                $db->set_prefix($this->database->prefix);
                $db->suppress_errors(true);
                self::assertNotFalse($db->query("CREATE TABLE biblio_cutover_guard (singleton INT PRIMARY KEY,purpose VARCHAR(64),environment_id VARCHAR(64),project VARCHAR(64),database_name VARCHAR(64),git_sha CHAR(40))"));
                self::assertNotFalse($db->insert("biblio_cutover_guard", ["singleton" => 1, "purpose" => $marker["purpose"], "environment_id" => $marker["environment_id"],
                    "project" => $project, "database_name" => $primaryName, "git_sha" => $marker["git_sha"]]));
            }
            $primary->query("SET FOREIGN_KEY_CHECKS=0");
            foreach ($this->database->get_col("SHOW TABLES") as $table) {
                $schema = $this->database->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_A)["Create Table"];
                self::assertNotFalse($primary->query($schema));
                foreach ($this->database->get_results("SELECT * FROM `{$table}`", ARRAY_A) as $row) {
                    self::assertNotFalse($primary->insert($table, $row));
                }
            }
            $primary->query("SET FOREIGN_KEY_CHECKS=1");
            foreach ($this->database->get_results("SHOW TRIGGERS", ARRAY_A) as $trigger) {
                self::assertNotFalse($primary->query($this->database->get_row("SHOW CREATE TRIGGER `{$trigger['Trigger']}`", ARRAY_A)["SQL Original Statement"]));
            }
            putenv("DDEV_SITENAME=" . $project);
            putenv("BIBLIO_REHEARSAL=1");
            $state = new RehearsalDatabaseState($primary, new CoreTableNames($primary->prefix));
            $target = new class($state, $primaryName, $project) implements RehearsalTarget {
                public function __construct(private RehearsalDatabaseState $state, private string $database, private string $project) {}
                public function identity(): array { return ["database" => $this->database, "project" => $this->project, "test_fixture" => true]; }
                public function fingerprint(): array { return $this->state->fingerprint(); }
                public function assertEmpty(): void {}
                public function acquire(): void {}
                public function release(): void {}
            };
            $before = $target->fingerprint();
            self::assertNotFalse($primary->query("ALTER DATABASE `{$primaryName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin"));
            self::assertNotSame($before, $target->fingerprint());
            self::assertSame($before["tables"], $target->fingerprint()["tables"]);
            self::assertSame("utf8mb4_bin", $target->fingerprint()["database_metadata"]["collation"]);
            self::assertNotFalse($primary->query("ALTER DATABASE `{$primaryName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"));
            self::assertSame($before, $target->fingerprint());
            try {
                new MariaDbRehearsalTransport($primary, $primaryName, "127.0.0.1", "root", "root", $marker, $project);
                self::fail("Wrong native endpoint accepted");
            } catch (RehearsalFailure $failure) {
                self::assertSame("transport_endpoint_mismatch", $failure->reason);
                self::assertSame($before, $target->fingerprint());
            }
            $store = new RehearsalBackupDirectory($directory . "/backups", $target,
                new MariaDbRehearsalTransport($primary, $primaryName, "db", "root", "root", $marker, $project),
                new MariaDbRehearsalTransport($probe, $probeName, "db", "root", "root", $marker, $project),
                new RehearsalEvidenceDirectory($directory . "/evidence"));
            $binding = ["environment" => $target->identity(), "synthetic_test" => true];
            self::assertNotFalse($probe->query("ALTER DATABASE `{$probeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin"));
            try { $store->create("PRE_APPLY", $binding); self::fail("Mismatched restore-probe defaults accepted"); }
            catch (RehearsalFailure $failure) {
                self::assertSame("backup_restore_proof_failed", $failure->reason);
                self::assertSame($before, $target->fingerprint());
            }
            self::assertNotFalse($probe->query("ALTER DATABASE `{$probeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"));
            $pre = $store->create("PRE_APPLY", $binding);
            self::assertTrue($pre["independent_restore_verified"]);
            self::assertSame(0400, fileperms($directory . "/backups/" . $pre["filename"]) & 0777);
            self::assertNotFalse($primary->insert($this->tableNames->works(), ["work_id" => "synthetic-native-probe", "work_title" => "Synthetic"]));
            self::assertNotSame($before, $target->fingerprint());
            $post = $store->create("POST_APPLY", $binding);
            self::assertNotSame($pre["sha256"], $post["sha256"]);
            $store->restore($pre, $binding);
            self::assertSame($before, $target->fingerprint());
            $changed = $pre;
            $changed["sha256"] = str_repeat("0", 64);
            try { $store->restore($changed, $binding); self::fail("Modified backup accepted"); }
            catch (RehearsalFailure) { self::assertSame($before, $target->fingerprint()); }
        } finally {
            putenv("DDEV_SITENAME=" . $projectBefore);
            putenv($flagBefore === false ? "BIBLIO_REHEARSAL" : "BIBLIO_REHEARSAL=" . $flagBefore);
            foreach ([$primaryName, $probeName] as $database) {
                $admin->query("DROP DATABASE IF EXISTS `{$database}`");
            }
            RehearsalFixture::remove($directory);
        }
    }
}
