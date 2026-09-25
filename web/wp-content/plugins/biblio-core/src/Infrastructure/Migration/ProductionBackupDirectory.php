<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Cutover\{RehearsalBackupStore, RehearsalContract, RehearsalDatabaseTransport, RehearsalEvidenceStore, ProductionMigrationTarget};
use Biblio\Core\Application\Migration\Runner\DeterministicJson;

final readonly class ProductionBackupDirectory implements RehearsalBackupStore
{
    public function __construct(
        private string $directory,
        private ProductionMigrationTarget $target,
        private RehearsalDatabaseTransport $database,
        private ?RehearsalDatabaseTransport $restoreProbe,
        private RehearsalEvidenceStore $evidence
    ) {
        RehearsalContract::require(is_dir($directory) && !is_link($directory)
            && realpath($directory) === $directory && (fileperms($directory) & 0077) === 0, "backup_root_unsafe");
        $identity = $target->identity();
        RehearsalContract::require(str_starts_with($directory, $identity["root"] . "/.local/")
            || (($identity["purpose"] ?? null) === "production-simulation" && str_starts_with($directory, sys_get_temp_dir() . "/biblio-production-test-")), "production_backup_location_forbidden");
        RehearsalContract::require($restoreProbe === null || $database->databaseId() !== $restoreProbe->databaseId(), "independent_restore_target_required");
    }

    public function create(string $phase, array $binding): array
    {
        if ($this->restoreProbe === null) { throw new \Biblio\Core\Application\Migration\Cutover\RehearsalFailure("independent_restore_target_required"); }
        RehearsalContract::require(in_array($phase, ["PRE_APPLY", "POST_APPLY"], true), "backup_phase_invalid");
        $identity = $this->target->identity();
        RehearsalContract::equal($identity, $binding["environment"], "backup_environment_changed");
        RehearsalContract::equal($this->database->databaseId(), $identity["database"], "backup_database_mismatch");
        if ($phase === "PRE_APPLY") {
            $this->target->assertEmpty();
        }
        $baseline = $this->target->fingerprint();
        $id = strtolower($phase) . "-" . gmdate("Ymd\THis\Z") . "-" . bin2hex(random_bytes(12));
        $path = $this->directory . "/" . $id . ".sql.gz";
        RehearsalContract::require(!file_exists($path), "backup_exists");
        $this->database->export($path);
        RehearsalContract::require(is_file($path) && !is_link($path) && filesize($path) > 0
            && (fileperms($path) & 0077) === 0, "backup_file_invalid");
        $hash = hash_file("sha256", $path);
        // A dump is accepted only after an independent, guarded native restore.
        $this->restoreProbe->import($path);
        RehearsalContract::equal($this->restoreProbe->fingerprint(), $baseline, "backup_restore_proof_failed");
        RehearsalContract::equal($this->target->fingerprint(), $baseline, "backup_target_changed");
        $receipt = [
            "backup_id" => $id, "phase" => $phase, "filename" => basename($path),
            "purpose" => $phase === "PRE_APPLY" ? "cutover-production-pre-apply" : "cutover-production-post-apply",
            "completed_run_id" => $binding["completed_run_id"] ?? null,
            "reconciliation_sha256" => $binding["reconciliation_sha256"] ?? null,
            "bytes" => filesize($path), "sha256" => $hash,
            "binding_sha256" => DeterministicJson::hash($binding),
            "environment_sha256" => DeterministicJson::hash($identity),
            "database" => $this->database->databaseId(),
            "restore_probe_database" => $this->restoreProbe->databaseId(),
            "baseline" => $baseline, "independent_restore_verified" => true,
        ];
        $sidecar = fopen($path . ".sha256", "xb");
        RehearsalContract::require(is_resource($sidecar), "backup_checksum_write_failed");
        $checksum = $hash . "  " . basename($path) . "\n";
        RehearsalContract::require(fwrite($sidecar, $checksum) === strlen($checksum), "backup_checksum_write_failed");
        fclose($sidecar);
        chmod($path, 0400);
        chmod($path . ".sha256", 0400);
        $receipt["artifact"] = $this->evidence->append("production-backup", $receipt);
        return $receipt;
    }

    public function verify(array $receipt, array $binding): void
    {
        RehearsalContract::require(is_array($receipt["artifact"] ?? null), "backup_receipt_required");
        $stored = $this->evidence->read($receipt["artifact"]);
        $material = $receipt;
        unset($material["artifact"]);
        RehearsalContract::equal($stored, $material, "backup_receipt_changed");
        $identity = $this->target->identity();
        RehearsalContract::equal($receipt["binding_sha256"] ?? null, DeterministicJson::hash($binding), "backup_binding_changed");
        RehearsalContract::equal($receipt["environment_sha256"] ?? null, DeterministicJson::hash($identity), "backup_environment_changed");
        RehearsalContract::require(($receipt["independent_restore_verified"] ?? null) === true, "backup_not_restore_verified");
        RehearsalContract::equal($receipt["database"] ?? null, $this->database->databaseId(), "backup_database_mismatch");
        $filename = $receipt["filename"] ?? null;
        RehearsalContract::require(is_string($filename) && preg_match('/^(pre_apply|post_apply)-[0-9TZ]+-[a-f0-9]{24}\.sql\.gz$/D', $filename) === 1, "backup_filename_invalid");
        $path = $this->directory . "/" . $filename;
        RehearsalContract::require(is_file($path) && !is_link($path) && !is_link($path . ".sha256")
            && (fileperms($path) & 0077) === 0, "backup_file_invalid");
        RehearsalContract::equal(hash_file("sha256", $path), $receipt["sha256"] ?? null, "backup_checksum_mismatch");
        RehearsalContract::equal(filesize($path), $receipt["bytes"] ?? null, "backup_size_mismatch");
        RehearsalContract::equal(file_get_contents($path . ".sha256"), $receipt["sha256"] . "  " . $filename . "\n", "backup_sidecar_mismatch");
    }

    public function restore(array $receipt, array $binding): void
    {
        RehearsalContract::require(($receipt["phase"] ?? null) === "PRE_APPLY", "rollback_requires_pre_apply");
        $this->verify($receipt, $binding);
        $this->database->import($this->directory . "/" . $receipt["filename"]);
        $this->target->identity();
        RehearsalContract::equal($this->target->fingerprint(), $receipt["baseline"], "rollback_baseline_mismatch");
        $this->target->assertEmpty();
    }
}
