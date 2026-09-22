<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Cutover\{RehearsalContract, RehearsalDatabaseTransport};
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

/** Native SQL transport. Credentials stay in child environment and never in argv or receipts. */
final readonly class MariaDbRehearsalTransport implements RehearsalDatabaseTransport
{
    /** @param array<string,string> $marker */
    public function __construct(
        private wpdb $db,
        private string $database,
        private string $host,
        private string $username,
        private string $password,
        private array $marker,
        private string $project
    ) {
        $this->assertPhysicalTarget();
    }

    public function databaseId(): string { return $this->database; }
    public function fingerprint(): array
    {
        return (new RehearsalDatabaseState($this->db, new CoreTableNames($this->db->prefix)))->fingerprint();
    }

    private function assertPhysicalTarget(): void
    {
        RehearsalContract::require(preg_match('/^biblio_cutover_[a-f0-9]{12}$/D', $this->database) === 1
            && preg_match('/^biblio-v2-cutover-[a-f0-9]{12}$/D', $this->project) === 1
            && getenv("DDEV_SITENAME") === $this->project && getenv("BIBLIO_REHEARSAL") === "1"
            && $this->db->get_var("SELECT DATABASE()") === $this->database,
            "transport_target_forbidden");
        // Restored probe databases carry the source backup marker. Its exact accepted
        // content is supplied separately from the physical restore database identity.
        $actual = $this->db->get_row("SELECT purpose,environment_id,project,database_name AS `database`,git_sha FROM biblio_cutover_guard WHERE singleton=1", ARRAY_A);
        RehearsalContract::equal($actual, $this->marker, "transport_marker_mismatch");
        RehearsalContract::require(($this->marker["purpose"] ?? null) === "MIG-CUTOVER-PREP-01B", "transport_marker_invalid");
        $this->assertNativeEndpoint();
    }

    /** Prove native CLI and wpdb reach the SAME server, not merely matching names. */
    private function assertNativeEndpoint(): void
    {
        $lock = "biblio-cutover-endpoint-" . bin2hex(random_bytes(16));
        RehearsalContract::require($this->db->get_var($this->db->prepare("SELECT GET_LOCK(%s,0)", $lock)) === "1", "transport_endpoint_lock_failed");
        try {
            $connection = $this->db->get_var("SELECT CONNECTION_ID()");
            $pipes = [];
            $process = proc_open([
                "mariadb", "--no-defaults", "--protocol=TCP", "--host=" . $this->host,
                "--port=3306", "--user=" . $this->username, "--batch", "--skip-column-names",
                "--database=" . $this->database,
                "--execute=SELECT IS_USED_LOCK('" . $lock . "')",
            ], [0 => ["file", "/dev/null", "r"], 1 => ["pipe", "w"], 2 => ["file", "/dev/null", "w"]], $pipes, null, $this->childEnvironment());
            RehearsalContract::require(is_resource($process), "transport_endpoint_unverified");
            $result = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $exit = proc_close($process);
            RehearsalContract::require($exit === 0 && is_string($result) && trim($result) === $connection, "transport_endpoint_mismatch");
        } finally {
            $this->db->get_var($this->db->prepare("SELECT RELEASE_LOCK(%s)", $lock));
        }
    }

    public function export(string $path): void
    {
        $this->assertPhysicalTarget();
        $file = @fopen($path, "xb");
        RehearsalContract::require(is_resource($file), "backup_exists");
        chmod($path, 0600);
        $filter = stream_filter_append($file, "zlib.deflate", STREAM_FILTER_WRITE, ["window" => 31]);
        RehearsalContract::require($filter !== false, "backup_compression_failed");
        $pipes = [];
        $process = proc_open([
            "mariadb-dump", "--no-defaults", "--protocol=TCP", "--port=3306", "--host=" . $this->host, "--user=" . $this->username,
            "--single-transaction", "--skip-comments", "--skip-dump-date", "--order-by-primary",
            "--hex-blob", "--triggers", "--routines", "--events", $this->database,
        ], [0 => ["file", "/dev/null", "r"], 1 => ["pipe", "w"], 2 => ["file", "/dev/null", "w"]], $pipes, null, $this->childEnvironment());
        RehearsalContract::require(is_resource($process), "backup_export_failed");
        try {
            RehearsalContract::require(stream_copy_to_stream($pipes[1], $file) !== false, "backup_export_failed");
            fclose($pipes[1]);
            RehearsalContract::require(proc_close($process) === 0, "backup_export_failed");
        } finally {
            fclose($file);
        }
    }

    public function import(string $path): void
    {
        $this->assertPhysicalTarget();
        RehearsalContract::require(is_file($path) && !is_link($path), "restore_file_invalid");
        $check = proc_open(["gzip", "-t", "--", $path],
            [0 => ["file", "/dev/null", "r"], 1 => ["file", "/dev/null", "w"], 2 => ["file", "/dev/null", "w"]], $checkPipes);
        RehearsalContract::require(is_resource($check) && proc_close($check) === 0, "restore_gzip_invalid");
        $gzip = @gzopen($path, "rb");
        RehearsalContract::require(is_resource($gzip), "restore_gzip_invalid");
        // The native import drops/recreates only this positively identified disposable database.
        $pipes = [];
        $process = proc_open(["mariadb", "--no-defaults", "--protocol=TCP", "--port=3306", "--host=" . $this->host, "--user=" . $this->username, "--binary-mode"],
            [0 => ["pipe", "r"], 1 => ["file", "/dev/null", "w"], 2 => ["file", "/dev/null", "w"]], $pipes, null, $this->childEnvironment());
        RehearsalContract::require(is_resource($process), "restore_process_failed");
        $schema = $this->db->get_row("SELECT DEFAULT_CHARACTER_SET_NAME AS charset,DEFAULT_COLLATION_NAME AS collation FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()", ARRAY_A);
        RehearsalContract::require(is_array($schema) && preg_match('/^[a-zA-Z0-9_]+$/D', $schema["charset"]) === 1
            && preg_match('/^[a-zA-Z0-9_]+$/D', $schema["collation"]) === 1, "restore_schema_invalid");
        $preamble = "DROP DATABASE `{$this->database}`; CREATE DATABASE `{$this->database}` CHARACTER SET {$schema['charset']} COLLATE {$schema['collation']}; USE `{$this->database}`;\n";
        try {
            RehearsalContract::require(fwrite($pipes[0], $preamble) === strlen($preamble), "restore_write_failed");
            while (!gzeof($gzip)) {
                $chunk = gzread($gzip, 65536);
                RehearsalContract::require(is_string($chunk) && fwrite($pipes[0], $chunk) === strlen($chunk), "restore_write_failed");
            }
            fclose($pipes[0]);
            RehearsalContract::require(proc_close($process) === 0, "restore_command_failed");
        } finally {
            gzclose($gzip);
        }
        $this->db->select($this->database);
        RehearsalContract::require($this->db->get_var("SELECT DATABASE()") === $this->database, "restore_reconnect_failed");
        $this->assertPhysicalTarget();
    }

    /** @return array<string,string> */
    private function childEnvironment(): array
    {
        return ["PATH" => (string) getenv("PATH"), "MYSQL_PWD" => $this->password, "LANG" => "C"];
    }
}
