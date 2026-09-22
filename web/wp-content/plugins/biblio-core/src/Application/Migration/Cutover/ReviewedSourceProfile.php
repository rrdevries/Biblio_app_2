<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\Runner\{DeterministicJson, MigrationSourceInspection};

/** Exact approved profile admission; raw diagnostics remain visible, never erased. */
final readonly class ReviewedSourceProfile
{
    private function __construct(private string $profileDigest, private string $bundleDigest, private string $approvalDigest) {}

    public static function forApprovedCandidate(ApprovedRehearsalSource $source, MigrationSourceInspection $inspection): self
    {
        RehearsalContract::equal($inspection->package()->manifestDigest(), $source->intake->identity()->manifestSha256(), "reviewed_profile_manifest_mismatch");
        RehearsalContract::equal($inspection->adapter()->adapterId(), $source->intake->identity()->adapterId(), "reviewed_profile_adapter_mismatch");
        RehearsalContract::equal($inspection->profile()->sourceVersion(), $source->intake->identity()->sourceVersion(), "reviewed_profile_version_mismatch");
        RehearsalContract::equal(DeterministicJson::hash($inspection->sourcePayload()), $source->review["source_profile_digest"], "reviewed_profile_changed");
        // docs/118 section16, docs/119 section13 and docs/130 section23(C):
        // these remain package-retained non-observations, never invented product rows.
        RehearsalContract::require(array_diff($inspection->profile()->unknownCategories(), [
            "auxiliary_caches", "home_preferences", "migration_reports", "recommendation_state",
            "release_tracking", "taxonomy_aliases", "taxonomy_review_queue",
        ]) === [], "unreviewed_source_category");
        foreach ($inspection->profile()->findings() as $finding) {
            RehearsalContract::require(in_array($finding->toArray()["reason_code"], [
                "field_inventory", "private_values_omitted", "observed_raw_value", "reference_integrity",
                "idless_structure", "identity_profile", "product_decision_required", "source_representation_ambiguity", "source_profile",
            ], true), "unreviewed_source_finding");
        }
        return new self(DeterministicJson::hash($inspection->sourcePayload()), $source->bundle->digest(), DeterministicJson::hash($source->review));
    }

    public function matches(MigrationSourceInspection $inspection, ?string $bundleDigest): bool
    {
        return $bundleDigest === $this->bundleDigest && hash_equals($this->profileDigest, DeterministicJson::hash($inspection->sourcePayload()));
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return ["policy" => "reviewed-current-profile-v1", "profile_sha256" => $this->profileDigest,
            "bundle_sha256" => $this->bundleDigest, "approval_sha256" => $this->approvalDigest];
    }
}
