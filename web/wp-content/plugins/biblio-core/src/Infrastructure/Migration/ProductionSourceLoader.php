<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Cutover\{FinalPopulationContractBundle, FinalSourceDriftEngine, FinalSourceExportProvenance, FinalSourceIntakeReceipt, FinalSourcePackageIdentity, FinalSourcePlanningContext, FinalSourceRetentionMetadata, RehearsalContract};

/** Reconstitutes existing typed evidence in memory; never exports/extracts or approves a source. */
final class ProductionSourceLoader
{
    /** @param array<string,mixed> $config */
    public static function load(array $config): FinalSourcePlanningContext
    {
        $priorPath = $config["intake_evidence"];
        RehearsalContract::require(is_file($priorPath) && !is_link($priorPath), "source_evidence_missing");
        RehearsalContract::equal(hash_file("sha256", $priorPath), $config["intake_evidence_sha256"], "source_evidence_changed");
        $prior = json_decode((string) file_get_contents($priorPath), true, 512, JSON_THROW_ON_ERROR);
        $identity = self::identity($prior["intake"]["package"]);
        $factory = new FilesystemMigrationSourcePackageFactory();
        $package = $factory->build($config["extraction_root"]);
        RehearsalContract::equal($package->manifestDigest(), $identity->manifestSha256(), "candidate_manifest_changed");
        $export = $prior["intake"]["export_provenance"];
        $retention = $prior["intake"]["retention"];
        $receipt = new FinalSourceIntakeReceipt($identity,
            new FinalSourceExportProvenance($export["exported_at"], $export["operator_id"], $export["export_tool"], $export["export_tool_version"],
                $export["export_invocation_digest"], $export["source_build"], $export["source_runtime_fingerprint"], $export["freeze_evidence_id"]),
            new FinalSourceRetentionMetadata($retention["retained_package_id"], $retention["independent_copy_verification"], $retention["recovery_verification"]),
            $package, basename($config["archive"]), (int) filesize($config["archive"]), $config["extraction_root"]);
        $inspector = new FinalSourceInspector($factory);
        $builder = new CurrentV1FinalSourceSnapshotBuilder();
        $reference = $builder->build($inspector->inspect($config["reference_root"], new CurrentV1SourceAdapter()), self::identity($prior["compatibility_report"]["reference_package"]));
        $candidate = $builder->build($inspector->inspect($config["extraction_root"], new CurrentV1SourceAdapter()), $identity);
        $bundle = FinalPopulationContractBundle::fromReport($candidate, (new FinalSourceDriftEngine())->compare($reference, $candidate));
        RehearsalContract::equal($bundle->digest(), $config["bundle_sha256"], "production_bundle_changed");
        RehearsalContract::equal($bundle->digest(), $prior["contract_bundle_sha256"], "reviewed_bundle_changed");
        $context = new FinalSourcePlanningContext($receipt, $bundle, $config["review"], $config["archive"]);
        $context->verify($factory);
        return $context;
    }

    /** @param array<string,string> $row */
    private static function identity(array $row): FinalSourcePackageIdentity
    {
        return new FinalSourcePackageIdentity($row["logical_package_id"], $row["archive_sha256"], $row["manifest_sha256"], $row["source_family"], $row["source_version"], $row["adapter_id"]);
    }
}
