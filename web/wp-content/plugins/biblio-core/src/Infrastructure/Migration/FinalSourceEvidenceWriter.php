<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Cutover\FinalPopulationContractBundle;
use Biblio\Core\Application\Migration\Cutover\FinalSourceCompatibilityReport;
use Biblio\Core\Application\Migration\Cutover\FinalSourceIntakeReceipt;
use Biblio\Core\Application\Migration\Cutover\FinalSourceToolProvenance;
use Biblio\Core\Application\Migration\Runner\DeterministicJson;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerFailure;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerReason;

final readonly class FinalSourceEvidenceWriter
{
    public function writePreparation(
        FinalSourceIntakeReceipt $intake,
        FinalSourceCompatibilityReport $report,
        FinalPopulationContractBundle $bundle,
        FinalSourceToolProvenance $tool,
        string $referenceManifestSha256,
        string $attemptId,
        string $outputRoot
    ): MigrationArtifactReceipt {
        if (
            preg_match('/^[a-f0-9]{64}$/D', $referenceManifestSha256) !== 1
            || preg_match('/^[a-z0-9][a-z0-9-]{2,126}$/D', $attemptId) !== 1
        ) {
            throw $this->failure("Final source evidence identity is invalid.");
        }
        $source = realpath($intake->extractionRoot());
        if (!is_string($source)) {
            throw $this->failure("Final source extraction disappeared before evidence write.");
        }
        $root = $this->prepareOutputRoot($outputRoot, $source);
        $attempt = $root . DIRECTORY_SEPARATOR . $intake->identity()->packageId()
            . DIRECTORY_SEPARATOR . $attemptId;
        if (file_exists($attempt) || is_link($attempt)) {
            throw $this->failure("Final source evidence attempt already exists; overwrite is forbidden.");
        }
        if (!@mkdir($attempt, 0700, true)) {
            throw $this->failure("Could not create immutable final source evidence attempt.");
        }

        $payload = [
            "artifact_schema" => "mig-cutover-prep-01a-v1",
            "artifact_kind" => "final-source-intake-drift-contract",
            "attempt_id" => $attemptId,
            "tool_provenance" => $tool->toArray(),
            "candidate_package_id" => $intake->identity()->packageId(),
            "archive_sha256" => $intake->identity()->archiveSha256(),
            "manifest_sha256" => $intake->identity()->manifestSha256(),
            "adapter_id" => $intake->identity()->adapterId(),
            "reference_manifest_sha256" => $referenceManifestSha256,
            "contract_bundle_sha256" => $bundle->digest(),
            "intake" => $intake->toArray(),
            "compatibility_report" => $report->toArray(),
            "final_population_contract_bundle" => $bundle->toArray(),
            "zero_write" => true,
            "production_apply_authorized" => false,
        ];
        $json = DeterministicJson::encode($payload, true) . "\n";
        $checksum = hash("sha256", $json);
        $filename = "artifact-{$checksum}.json";
        $artifactPath = $attempt . DIRECTORY_SEPARATOR . $filename;
        $checksumPath = $artifactPath . ".sha256";
        $this->atomicWriteNew($artifactPath, $json);
        $this->atomicWriteNew(
            $checksumPath,
            $checksum . "  {$filename}\n"
        );
        @chmod($artifactPath, 0400);
        @chmod($checksumPath, 0400);
        @chmod($attempt, 0500);

        return new MigrationArtifactReceipt($artifactPath, $checksumPath, $checksum);
    }

    private function prepareOutputRoot(string $outputRoot, string $source): string
    {
        if ($outputRoot === "" || is_link($outputRoot)) {
            throw $this->failure("Final source evidence root is unsafe.");
        }
        if (!is_dir($outputRoot) && !@mkdir($outputRoot, 0700, true)) {
            throw $this->failure("Could not create final source evidence root.");
        }
        $root = realpath($outputRoot);
        if (!is_string($root) || !is_writable($root)) {
            throw $this->failure("Final source evidence root is not writable.");
        }
        if ($root === $source || str_starts_with($root . DIRECTORY_SEPARATOR, $source . DIRECTORY_SEPARATOR)) {
            throw $this->failure("Final source evidence may not be written inside source root.");
        }
        return $root;
    }

    private function atomicWriteNew(string $path, string $bytes): void
    {
        try {
            $temporary = dirname($path) . DIRECTORY_SEPARATOR
                . ".tmp-" . bin2hex(random_bytes(8));
        } catch (\Throwable $exception) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::ArtifactWriteFailed,
                "Could not allocate final source evidence temporary path.",
                $exception
            );
        }
        $written = @file_put_contents($temporary, $bytes, LOCK_EX);
        if (
            $written !== strlen($bytes)
            || file_exists($path)
            || !@rename($temporary, $path)
        ) {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
            throw $this->failure("Could not atomically write final source evidence.");
        }
    }

    private function failure(string $message): MigrationRunnerFailure
    {
        return new MigrationRunnerFailure(MigrationRunnerReason::ArtifactWriteFailed, $message);
    }
}
