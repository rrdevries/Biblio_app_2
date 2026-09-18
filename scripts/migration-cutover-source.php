<?php

declare(strict_types=1);

use Biblio\Core\Application\Migration\Cutover\FinalPopulationContractBundle;
use Biblio\Core\Application\Migration\Cutover\FinalSourceDriftEngine;
use Biblio\Core\Application\Migration\Cutover\FinalSourceExportProvenance;
use Biblio\Core\Application\Migration\Cutover\FinalSourcePackageIdentity;
use Biblio\Core\Application\Migration\Cutover\FinalSourceRetentionMetadata;
use Biblio\Core\Application\Migration\Cutover\FinalSourceToolProvenance;
use Biblio\Core\Infrastructure\Migration\CurrentV1FinalSourceSnapshotBuilder;
use Biblio\Core\Infrastructure\Migration\CurrentV1ReviewedClassificationContract;
use Biblio\Core\Infrastructure\Migration\CurrentV1SourceAdapter;
use Biblio\Core\Infrastructure\Migration\FilesystemMigrationSourcePackageFactory;
use Biblio\Core\Infrastructure\Migration\FinalSourceEvidenceWriter;
use Biblio\Core\Infrastructure\Migration\FinalSourceInspector;
use Biblio\Core\Infrastructure\Migration\FinalSourceIntakeService;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;
use Biblio\Core\Plugin;

$repository = dirname(__DIR__);
$autoload = $repository . "/web/wp-content/plugins/biblio-core/vendor/autoload.php";
if (!is_file($autoload)) {
    fwrite(STDERR, "PREP-01A failed: Biblio Core dependencies are unavailable.\n");
    exit(1);
}
require $autoload;

try {
    $arguments = $argv;
    array_shift($arguments);
    $mode = array_shift($arguments);
    if ($mode !== "prepare") {
        throw new InvalidArgumentException("Only the zero-write 'prepare' mode is available.");
    }
    $options = parseOptions($arguments);
    $required = static function (string $name) use ($options): string {
        $value = $options[$name] ?? null;
        if (!is_string($value) || trim($value) === "") {
            throw new InvalidArgumentException("Required --{$name}=... was not supplied.");
        }
        return $value;
    };

    $adapter = new CurrentV1SourceAdapter();
    $factory = new FilesystemMigrationSourcePackageFactory();
    $intake = (new FinalSourceIntakeService($factory))->intake(
        $required("archive"),
        $required("archive-sha256"),
        $required("manifest-sha256"),
        $required("package-id"),
        $required("intake-root"),
        $adapter,
        CurrentV1SourceAdapter::SOURCE_VERSION,
        new FinalSourceExportProvenance(
            $required("exported-at"),
            $required("operator-id"),
            $required("export-tool"),
            $required("export-tool-version"),
            $required("export-invocation-sha256"),
            $required("source-build"),
            $required("source-runtime-fingerprint"),
            $required("freeze-evidence-id")
        ),
        new FinalSourceRetentionMetadata(
            $required("retained-package-id"),
            $required("independent-copy-verification"),
            $required("recovery-verification")
        )
    );

    $inspector = new FinalSourceInspector($factory);
    $referenceInspection = $inspector->inspect($required("reference-root"), $adapter);
    if (!hash_equals(
        CurrentV1ReviewedClassificationContract::MANIFEST_SHA256,
        $referenceInspection->package()->manifestDigest()
    )) {
        throw new RuntimeException("Reviewed CURRENT reference manifest is not exact.");
    }
    $candidateInspection = $inspector->inspect($intake->extractionRoot(), $adapter);
    $builder = new CurrentV1FinalSourceSnapshotBuilder();
    $reference = $builder->build(
        $referenceInspection,
        new FinalSourcePackageIdentity(
            "reviewed-current-35a18156490f",
            $required("reference-archive-sha256"),
            $referenceInspection->package()->manifestDigest(),
            $adapter->sourceFamily(),
            $referenceInspection->profile()->sourceVersion(),
            $adapter->adapterId()
        )
    );
    $candidate = $builder->build($candidateInspection, $intake->identity());
    $report = (new FinalSourceDriftEngine())->compare($reference, $candidate);
    $bundle = FinalPopulationContractBundle::fromReport($candidate, $report);
    $tool = toolProvenance($repository);
    $artifact = (new FinalSourceEvidenceWriter())->writePreparation(
        $intake,
        $report,
        $bundle,
        $tool,
        CurrentV1ReviewedClassificationContract::MANIFEST_SHA256,
        $required("attempt-id"),
        $required("evidence-root")
    );

    fwrite(STDOUT, json_encode([
        "result" => $report->compatibility(),
        "approval_state" => $bundle->approvalState()->value,
        "artifact_path" => $artifact->artifactPath(),
        "artifact_sha256" => $artifact->checksum(),
        "contract_bundle_sha256" => $bundle->digest(),
        "zero_write" => true,
        "production_apply_authorized" => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit($report->compatibility() === "CONTRACT_REVIEW_REQUIRED" ? 2 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, "PREP-01A failed without exposing source content: " . $exception->getMessage() . "\n");
    exit(1);
}

/** @param list<string> $arguments @return array<string,string> */
function parseOptions(array $arguments): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if (!str_starts_with($argument, "--") || !str_contains($argument, "=")) {
            throw new InvalidArgumentException("PREP-01A options must use --name=value form.");
        }
        [$name, $value] = explode("=", substr($argument, 2), 2);
        if ($name === "" || $value === "" || isset($options[$name])) {
            throw new InvalidArgumentException("PREP-01A option is empty or duplicated.");
        }
        $options[$name] = $value;
    }
    return $options;
}

function toolProvenance(string $repository): FinalSourceToolProvenance
{
    $gitSha = trim((string) shell_exec(
        "git -C " . escapeshellarg($repository) . " rev-parse HEAD 2>/dev/null"
    ));
    $status = shell_exec(
        "git -C " . escapeshellarg($repository) . " status --porcelain 2>/dev/null"
    );
    $manifest = json_decode((string) file_get_contents($repository . "/manifest.json"), true);
    $uiPlugin = (string) file_get_contents(
        $repository . "/web/wp-content/plugins/biblio-ui/biblio-ui.php"
    );
    preg_match('/^ \* Version:\s*([^\r\n]+)$/m', $uiPlugin, $uiMatch);
    $runtime = "php=" . PHP_VERSION
        . ";zip=" . (phpversion("zip") ?: "unknown")
        . ";json=" . (phpversion("json") ?: "bundled")
        . ";mbstring=" . (phpversion("mbstring") ?: "unknown");

    return new FinalSourceToolProvenance(
        $gitSha,
        !is_string($status) || trim($status) !== "",
        is_array($manifest) && is_string($manifest["version"] ?? null)
            ? $manifest["version"]
            : "unknown",
        CoreSchemaMigrator::CURRENT_VERSION,
        Plugin::VERSION,
        isset($uiMatch[1]) ? trim($uiMatch[1]) : "unknown",
        $runtime
    );
}
