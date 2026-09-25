<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Cutover\{RehearsalContract, RehearsalEvidenceStore};
use Biblio\Core\Application\Migration\Runner\DeterministicJson;

final readonly class ProductionEvidenceDirectory implements RehearsalEvidenceStore
{
    public function __construct(private string $directory)
    {
        RehearsalContract::require(is_dir($directory) && !is_link($directory)
            && realpath($directory) === $directory && (fileperms($directory) & 0077) === 0, "evidence_root_unsafe");
    }

    public function append(string $kind, array $payload): array
    {
        RehearsalContract::id($kind);
        RehearsalContract::require(!str_contains($kind, "/"), "evidence_kind_invalid");
        $id = $kind . "-" . gmdate("Ymd\THis\Z") . "-" . bin2hex(random_bytes(12));
        $bytes = DeterministicJson::encode([
            "artifact_schema" => "mig-cutover-production-v1",
            "receipt_id" => $id,
            "purpose" => "production_cutover_evidence",
            "receipt_grants_authorization" => false,
            "evidence" => $payload,
        ], true) . "\n";
        $hash = hash("sha256", $bytes);
        $attempt = $this->directory . "/" . $id;
        RehearsalContract::require(@mkdir($attempt, 0700), "evidence_attempt_exists");
        $name = "artifact-" . $hash . ".json";
        foreach ([$name => $bytes, $name . ".sha256" => $hash . "  " . $name . "\n"] as $filename => $content) {
            $file = @fopen($attempt . "/" . $filename, "xb");
            RehearsalContract::require(is_resource($file), "evidence_write_failed");
            try {
                RehearsalContract::require(fwrite($file, $content) === strlen($content) && fflush($file), "evidence_write_failed");
            } finally {
                fclose($file);
            }
            RehearsalContract::require(chmod($attempt . "/" . $filename, 0400), "evidence_permissions_failed");
        }
        RehearsalContract::require(chmod($attempt, 0500), "evidence_permissions_failed");
        return ["sha256" => $hash, "receipt_id" => $id];
    }

    public function read(array $reference): array
    {
        RehearsalContract::hash($reference["sha256"]);
        $id = $reference["receipt_id"];
        RehearsalContract::require(preg_match('/^[a-z][a-z0-9-]*-[0-9TZ]+-[a-f0-9]{24}$/D', $id) === 1, "evidence_reference_invalid");
        $parent = $this->directory . "/" . $id;
        $path = $parent . "/artifact-" . $reference["sha256"] . ".json";
        RehearsalContract::require(is_dir($parent) && !is_link($parent) && is_file($path) && !is_link($path)
            && is_file($path . ".sha256") && !is_link($path . ".sha256")
            && (fileperms($parent) & 0077) === 0 && (fileperms($path) & 0077) === 0, "evidence_file_unsafe");
        $bytes = file_get_contents($path);
        RehearsalContract::require(is_string($bytes), "evidence_unreadable");
        RehearsalContract::equal(hash("sha256", $bytes), $reference["sha256"], "evidence_checksum_mismatch");
        RehearsalContract::equal(file_get_contents($path . ".sha256"), $reference["sha256"] . "  " . basename($path) . "\n", "evidence_sidecar_mismatch");
        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        RehearsalContract::require(is_array($decoded) && ($decoded["receipt_id"] ?? null) === $id
            && ($decoded["purpose"] ?? null) === "production_cutover_evidence" && ($decoded["receipt_grants_authorization"] ?? null) === false
            && is_array($decoded["evidence"] ?? null), "evidence_envelope_invalid");
        return $decoded["evidence"];
    }
}
