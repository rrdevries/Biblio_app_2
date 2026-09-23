<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\Runner\DeterministicJson;

/** One owner-approved completion, not a generic lifecycle or precision exception. */
final readonly class ReviewedReadingRoundCompletionDisposition
{
    private const BOOK_ID = "1771014295306";
    private const ROUND_ID = "rr-1-20260915T133135697-na";
    private const REFERENCE_PACKAGE = "d720dacf37a611761549525d33d186f64bd9b3ee9bf5bc4f68feb5edfd9caf69";
    private const FINAL_PACKAGE = "0ac55dbac2b98b4e2141573a94a3aa600d4cf3bdda8fc72a827574cf0cadac4a";
    private const OLD_PAYLOAD = "06e3d14f8a801bc2094333ae5f8fe97e1e388c3fe076202e883d05416e75dff7";
    private const NEW_PAYLOAD = "145d3f0e42f2451e0d68a63c1c6bd0aa60e33faea0278df7a081ffb9b08abdf0";
    private const OLD_POPULATION = "e61b23bf4e1ca3d92e0f4bb327057d759c59a1b1fd6b45181bb252bfcfc8b60a";
    private const NEW_POPULATION = "3d88379c2ad5017e8931c18a036a50d8e933dad3ce9857ca93f19295fbdc2576";

    private function __construct() {}

    public static function forSnapshots(FinalSourceSnapshot $reference, FinalSourceSnapshot $candidate): ?self
    {
        // Full package digests bind logical ID, archive, manifest, family/version and adapter.
        // Production snapshot construction verifies the manifest against inspected source bytes.
        if ($reference->package()->digest() !== self::REFERENCE_PACKAGE
            || $candidate->package()->digest() !== self::FINAL_PACKAGE
            || !self::matchesSnapshot($reference, false)
            || !self::matchesSnapshot($candidate, true)
        ) {
            return null;
        }
        return new self();
    }

    private static function matchesSnapshot(FinalSourceSnapshot $snapshot, bool $candidate): bool
    {
        $targetMatches = false;
        $rounds = [];
        foreach ($snapshot->observations() as $observation) {
            if ($observation->domain() !== "reading_rounds") {
                continue;
            }
            $rounds[$observation->key()] = $observation->toArray();
            if ($observation->sourceId() === self::ROUND_ID) {
                $targetMatches = $observation->toArray() === self::roundObservation($candidate);
            }
        }
        ksort($rounds, SORT_STRING);
        // Include every observation in the domain, including unexpected source types.
        return $targetMatches && count($rounds) === 53
            && ($snapshot->sourceTypeCounts()["v1.reading_round"] ?? null) === 53
            && DeterministicJson::hash(array_values($rounds))
                === ($candidate ? self::NEW_POPULATION : self::OLD_POPULATION);
    }

    public function appliesTo(
        string $domain,
        string $sourceType,
        string $sourceId,
        SourceDriftCategory $category,
        string $reason,
        ?string $before,
        ?string $after
    ): bool {
        return $domain === "reading_rounds" && $sourceType === "v1.reading_round"
            && $sourceId === self::ROUND_ID && $category === SourceDriftCategory::E
            && $reason === "new_raw_value_enum_or_source_shape"
            && $before === self::OLD_PAYLOAD && $after === self::NEW_PAYLOAD;
    }

    /** @return array<string,mixed> */
    private static function roundObservation(bool $candidate): array
    {
        return [
            "domain" => "reading_rounds", "source_type" => "v1.reading_round", "source_id" => self::ROUND_ID,
            // Full wrapped payload includes the exact parent Book ID and all raw round fields.
            "payload_hash" => $candidate ? self::NEW_PAYLOAD : self::OLD_PAYLOAD,
            "shape_hash" => $candidate
                ? "a14e8975a1c1b840e8ab2eb1e54f4da197ebb06ef1349d74f1f78d9f37db98af"
                : "c11ed039a457dfdf4a1bd58c90b4c7abac71deb98e31d066320e34f4bdff623f",
            "semantic_class" => "reviewed_current_v1_shape", "structural" => false, "state" => "ordinary",
        ];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            "disposition_id" => "final-reading-round-completion-20260923-v1",
            "mechanical_category" => "E", "effective_category" => "C",
            "reviewed_disposition" => "CURRENT USER DATA — EXISTING CONTRACT COMPATIBLE",
            "reason_code" => "reviewed_exact_reading_round_completion",
            "approval_sha256" => "5e170abda7c68f6556778746ceb00e8456af931a73a819e174e1054c32c8935a",
            "review_evidence_sha256" => "dd6c9cbb89a87fa7a30745641888372cbec43061a7a582b42d4467d83b084c70",
            "review_closure_sha256" => "bf22f5a2f583c9d13c03c8778273e50bf5a9108649d18be581168ebec16394d9",
            "population_evidence_sha256" => "a7693037ce1972a5ebc8971c6f995f990e955549f7684e8dd231258d4f90b813",
            "mapper_comparison_sha256" => "40961a69e1490f142cba1db6dd5edf3068e7aefe1cc2755780b4726055309f83",
            "reference_package_digest" => self::REFERENCE_PACKAGE,
            "candidate_package_digest" => self::FINAL_PACKAGE,
            "book_source_id" => self::BOOK_ID, "round_source_id" => self::ROUND_ID,
            "reference_observation" => self::roundObservation(false),
            "candidate_observation" => self::roundObservation(true),
            "round_population" => ["reference_count" => 53, "candidate_count" => 53,
                "reference_sha256" => self::OLD_POPULATION, "candidate_sha256" => self::NEW_POPULATION,
                "unchanged_other_rounds" => 52],
            "field_transitions" => [
                "finishedAt" => ["before" => "EMPTY_STRING", "after" => "NOON_UTC_REPRESENTATION"],
                "finishedAtPartial" => ["before" => "NULL", "after" => "DAY_PRECISION_OBJECT"],
            ],
            "precision" => ["operational" => "day", "exact_clock_completion_proven" => false],
            "reviewed_mapper_effect" => [
                "old_reason" => "active_round_missing_concrete_source",
                "new_reason" => "stable_reading_round_planned", "new_plan_count" => 1,
                "lifecycle" => "ended", "outcome" => "completed", "source" => null,
                "provenance" => "migration_imported", "plans_before" => 53, "plans_after" => 54,
                "completed_before" => 51, "completed_after" => 52, "stopped_before_and_after" => 2,
                // Audit comparison only; never a production/rehearsal plan-set digest.
                "diagnostic_target_user_id" => "review-user",
                "diagnostic_plan_payload_sha256" => "87bc9d8696b1fa30124e7f5fea9d2be41af713e3f968421f35a76bf5a64f867e",
                "prt_plans_unchanged" => 1064,
                "diagnostic_prt_sha256" => "ef99096aa92c551b8ca52612570ac818b5600df63efe398745ab77db39e414e8",
            ],
            "preservation" => ["scope" => "complete_immutable_restricted_source",
                "raw_finish_fields_and_book_save_history_retained" => true,
                "new_preservation_plan_or_reason" => false, "derived_history_creates_rounds" => false],
            "negative_boundaries" => ["other_book_or_round", "other_package_or_manifest",
                "changed_start_or_pauses", "stopped_or_conflicting_lifecycle", "removed_end",
                "different_date_or_precision", "absent_null_empty_mismatch", "extra_round",
                "completion_from_updated_at_or_history", "reread_or_item_inference", "prt_output_change",
                "clock_time_promotion"],
            "reading_mapper_semantics_changed" => false, "prt_semantics_changed" => false,
            "circulation_disposition_included" => false,
        ];
    }
}
