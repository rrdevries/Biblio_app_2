<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\Runner\DeterministicJson;
use Biblio\Core\Application\Migration\Runner\MigrationSourcePackageFactory;

/** Exact source and reviewed planning facts; never an apply authorization. */
final readonly class FinalSourcePlanningContext
{
    /** @param array<string,mixed> $review */
    public function __construct(
        public FinalSourceIntakeReceipt $intake,
        public FinalPopulationContractBundle $bundle,
        public array $review,
        private string $archivePath
    ) {
        RehearsalContract::keys($review, [
            "package_digest", "bundle_digest", "source_profile_digest", "quarantine",
            "author_exceptions", "occurrence_exceptions", "copy_exclusions",
        ]);
        RehearsalContract::require(in_array($bundle->approvalState(), [
            FinalSourceApprovalState::MechanicallyCompatible,
            FinalSourceApprovalState::ReviewedCompatible,
        ], true), "contract_review_required");
        RehearsalContract::hash($review["source_profile_digest"]);
        RehearsalContract::equal($review["package_digest"], $intake->identity()->digest(), "review_package_mismatch");
        RehearsalContract::equal($review["bundle_digest"], $bundle->digest(), "review_bundle_mismatch");
        RehearsalContract::equal($bundle->toArray()["package"], $intake->identity()->toArray(), "bundle_package_mismatch");
        foreach (["quarantine", "author_exceptions", "occurrence_exceptions", "copy_exclusions"] as $key) {
            RehearsalContract::require(is_array($review[$key]) && array_is_list($review[$key]), "review_population_invalid");
        }
        foreach ($review["copy_exclusions"] as $row) {
            RehearsalContract::require(is_array($row), "review_population_invalid");
            RehearsalContract::keys($row, ["source_id", "payload_hash"]);
            RehearsalContract::id($row["source_id"]);
            RehearsalContract::hash($row["payload_hash"]);
        }
        foreach (["author_exceptions", "occurrence_exceptions"] as $key) {
            foreach ($review[$key] as $row) {
                RehearsalContract::require(is_array($row), "review_population_invalid");
                RehearsalContract::keys($row, ["source_id", "parent_source_type", "parent_source_id", "payload_hash", "reason"]);
                foreach (["source_id", "parent_source_type", "parent_source_id", "reason"] as $field) {
                    RehearsalContract::id($row[$field]);
                }
                RehearsalContract::hash($row["payload_hash"]);
            }
        }
        foreach ($review["quarantine"] as $row) {
            RehearsalContract::require(is_array($row), "review_quarantine_invalid");
            RehearsalContract::keys($row, ["source_type", "source_id", "payload_hash", "reason_code", "evidence_hash", "no_target"]);
            foreach (["source_type", "source_id", "reason_code"] as $field) {
                RehearsalContract::id($row[$field]);
            }
            RehearsalContract::hash($row["payload_hash"]);
            RehearsalContract::hash($row["evidence_hash"]);
            RehearsalContract::require($row["no_target"] === true, "quarantine_has_target");
        }
    }

    public function reviewDigest(): string { return DeterministicJson::hash($this->review); }

    public function verify(MigrationSourcePackageFactory $packages): void
    {
        RehearsalContract::require(is_file($this->archivePath) && !is_link($this->archivePath), "candidate_archive_unavailable");
        RehearsalContract::equal(hash_file("sha256", $this->archivePath), $this->intake->identity()->archiveSha256(), "candidate_archive_changed");
        $current = $packages->build($this->intake->extractionRoot());
        RehearsalContract::equal($current->manifestDigest(), $this->intake->identity()->manifestSha256(), "candidate_manifest_changed");
    }
}
