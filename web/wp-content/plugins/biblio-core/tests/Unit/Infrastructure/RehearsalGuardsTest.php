<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Migration\Cutover\{RehearsalAuthorization, RehearsalEnvironmentIdentity, RehearsalFailure, RehearsalFault};
use Biblio\Core\Application\Migration\Runner\DeterministicJson;
use Biblio\Core\Infrastructure\Migration\RehearsalEvidenceDirectory;
use PHPUnit\Framework\TestCase;

final class RehearsalGuardsTest extends TestCase
{
    public function testAll57TableCountsRejectAnUnexpectedRow(): void
    {
        $tables = new \Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames("synthetic_");
        $schema = $tables->schema1026();
        $allowed = [$tables->libraries() => 1, $tables->memberships() => 1, $tables->personalLibraryDesignations() => 1,
            $tables->libraryBookTypes() => 9, $tables->libraryGenres() => 12];
        $states = [];
        foreach ($schema as $table) { $states[$table] = ["rows" => $allowed[$table] ?? 0]; }
        \Biblio\Core\Infrastructure\Migration\RehearsalDatabaseState::assertEmptyCounts($schema, $states, $allowed);
        self::assertCount(57, $states);
        foreach ($schema as $table) {
            $changed = $states;
            ++$changed[$table]["rows"];
            try { \Biblio\Core\Infrastructure\Migration\RehearsalDatabaseState::assertEmptyCounts($schema, $changed, $allowed); self::fail("Unexpected row accepted"); }
            catch (RehearsalFailure $failure) { self::assertSame("target_not_empty", $failure->reason); }
        }
    }

    /** @return array<string,mixed> */
    public static function identity(): array
    {
        $identity = [
            "environment_id" => "synthetic-cutover-001", "project" => "biblio-v2-cutover-abcdef012345",
            "database" => "biblio_cutover_abcdef012345", "root" => "/isolated/synthetic-cutover",
            "git_sha" => str_repeat("a", 40), "dirty" => false,
            "runtime" => ["wordpress" => "synthetic-wp", "php" => "8.3.synthetic", "mariadb" => "10.11.synthetic",
                "charset" => "utf8mb4", "collation" => "utf8mb4_unicode_ci", "sql_mode" => "STRICT_TRANS_TABLES",
                "extensions" => ["json", "mbstring", "mysqli", "openssl", "pdo_mysql", "zip"],
                "product" => "v2.001", "core" => "synthetic-core", "ui" => "0.20.0", "schema" => 1026,
                "ddev" => "synthetic-ddev", "dependency_sha256" => str_repeat("b", 64)],
            "target_user_id" => "321", "target_library_id" => "synthetic-library",
            "roles" => ["subscriber"], "super_admin" => false, "environment_type" => "local", "writes_frozen" => true,
        ];
        $identity["marker"] = ["purpose" => "MIG-CUTOVER-PREP-01B", "environment_id" => $identity["environment_id"],
            "project" => $identity["project"], "database" => $identity["database"], "git_sha" => $identity["git_sha"]];
        return $identity;
    }

    public function testPositiveIdentityAndEveryBoundDriftRejectBeforePayload(): void
    {
        $expected = self::identity();
        RehearsalEnvironmentIdentity::assert($expected, $expected);
        $this->addToAssertionCount(1);
        $cases = [
            ["project", "biblio-v2"], ["project", "wrong-project"], ["database", "db"],
            ["database", "production"], ["database", "biblio_core_test"], ["marker", []],
            ["environment_type", "production"], ["writes_frozen", false], ["dirty", true],
            ["git_sha", str_repeat("f", 40)], ["root", "/wrong/root"],
            ["target_user_id", "1"], ["target_library_id", "wrong-library"],
            ["roles", ["administrator"]], ["roles", ["subscriber", "editor"]], ["super_admin", true],
        ];
        foreach ($expected["runtime"] as $field => $value) {
            $runtime = $expected["runtime"];
            $runtime[$field] = is_array($value) ? [] : (is_int($value) ? 1027 : "changed");
            $cases[] = ["runtime", $runtime];
        }
        foreach ($cases as [$field, $value]) {
            $actual = $expected;
            $actual[$field] = $value;
            $wrote = false;
            try {
                RehearsalEnvironmentIdentity::assert($actual, $expected);
                $wrote = true;
            } catch (RehearsalFailure $failure) {
                self::assertNotSame("", $failure->reason);
            }
            self::assertFalse($wrote, "Unsafe environment reached payload: " . $field);
        }
    }

    public function testAuthorizationRequiresExactIntentAndChangesForEveryInput(): void
    {
        $preflight = ["environment" => self::identity(), "source" => ["package" => str_repeat("a", 64)], "plan" => ["digest" => str_repeat("b", 64)]];
        $backup = ["phase" => "PRE_APPLY", "sha256" => str_repeat("c", 64)];
        $authorization = new RehearsalAuthorization($preflight, $backup, "review-1",
            RehearsalAuthorization::confirmationFor($preflight, $backup, true), true);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $authorization->intentDigest());
        $otherBackup = $backup + ["backup_id" => "different-attempt-and-instant"];
        $other = new RehearsalAuthorization($preflight, $otherBackup, "review-1", RehearsalAuthorization::confirmationFor($preflight, $otherBackup, true), true);
        self::assertSame($authorization->intentDigest(), $other->intentDigest());
        self::assertNotSame($authorization->authorizationDigest(), $other->authorizationDigest());
        foreach (["environment", "source", "plan"] as $field) {
            $changed = $preflight;
            $changed[$field] = ["changed" => true];
            self::assertNotSame(RehearsalAuthorization::confirmationFor($preflight, $backup, true),
                RehearsalAuthorization::confirmationFor($changed, $backup, true));
        }
        self::assertNotSame(RehearsalAuthorization::confirmationFor($preflight, $backup, true),
            RehearsalAuthorization::confirmationFor($preflight, $backup, true, RehearsalFault::AfterProductCommit));
        $this->expectException(RehearsalFailure::class);
        new RehearsalAuthorization($preflight, $backup, "review-1", "yes apply", true);
    }

    public function testContentAddressedEvidenceIsRestrictedAndAppendOnly(): void
    {
        $directory = sys_get_temp_dir() . "/biblio-rehearsal-evidence-test-" . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $writer = new RehearsalEvidenceDirectory($directory);
            $first = $writer->append("rehearsal-test", ["counts" => ["work" => 1], "digest" => DeterministicJson::hash(["synthetic" => true])]);
            $second = $writer->append("rehearsal-test", ["counts" => ["work" => 1]]);
            self::assertNotSame($first["receipt_id"], $second["receipt_id"]);
            $path = $directory . "/" . $first["receipt_id"] . "/artifact-" . $first["sha256"] . ".json";
            self::assertSame($first["sha256"], hash_file("sha256", $path));
            self::assertSame(0400, fileperms($path) & 0777);
            self::assertSame($first["sha256"] . "  " . basename($path) . "\n", file_get_contents($path . ".sha256"));
            self::assertFileDoesNotExist($directory . "/latest.json");
            self::assertSame(["counts" => ["work" => 1], "digest" => DeterministicJson::hash(["synthetic" => true])], $writer->read($first));
        } finally {
            foreach (glob($directory . "/*") ?: [] as $attempt) {
                chmod($attempt, 0700);
                foreach (glob($attempt . "/*") ?: [] as $file) { unlink($file); }
                rmdir($attempt);
            }
            rmdir($directory);
        }
    }
}
