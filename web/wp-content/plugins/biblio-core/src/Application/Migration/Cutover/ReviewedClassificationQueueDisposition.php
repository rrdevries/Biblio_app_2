<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\Runner\DeterministicJson;

/** One owner-approved queue delta. No configurable rules, normalization or mapping. */
final readonly class ReviewedClassificationQueueDisposition
{
    private const REFERENCE_PACKAGE = "d720dacf37a611761549525d33d186f64bd9b3ee9bf5bc4f68feb5edfd9caf69";
    private const FINAL_PACKAGE = "0ac55dbac2b98b4e2141573a94a3aa600d4cf3bdda8fc72a827574cf0cadac4a";
    private const OLD_QUEUE = "a37d40c73f73b496500d87cbc564127b00d54f828c0e34739fb3a1864ea4b864";
    private const NEW_QUEUE = "4a0e25f651005c83f021fd9d7350cbab03196fff2c9dd663affb520e28ab0bcd";
    private const QUEUE_SHAPE = "dcb452a982945e5e2957930d83d36af5ceee19805ec0c3b30529ae8f44f6e49e";
    private const OTHER_CLASSIFICATIONS = "f4c8e58812cdd86cad525573b0fba21c5685cfe2004b2a3f2ea1964b75e912e8";

    private function __construct() {}

    public static function forSnapshots(FinalSourceSnapshot $reference, FinalSourceSnapshot $candidate): ?self
    {
        // Full package identity binds logical ID, archive, manifest, family/version and adapter.
        // The source builder verifies that manifest against actual inspected source bytes.
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
        $queueMatches = false;
        $other = [];
        foreach ($snapshot->observations() as $observation) {
            if ($observation->domain() !== "classifications") {
                continue;
            }
            if ($observation->sourceType() === "v1.classification_review_vector"
                && $observation->sourceId() === "classification-review-queue"
            ) {
                $queueMatches = $observation->toArray() === self::queueObservation($candidate);
            } else {
                $other[$observation->key()] = $observation->toArray();
            }
        }
        ksort($other, SORT_STRING);
        // 1,139 assignments + 65 classification definitions + 3 Carriers + 1 alias aggregate.
        // Do not filter unknown classification observations or ignore timestamp/order fields.
        return $queueMatches && count($other) === 1208
            && DeterministicJson::hash(array_values($other)) === self::OTHER_CLASSIFICATIONS;
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
        return $domain === "classifications" && $sourceType === "v1.classification_review_vector"
            && $sourceId === "classification-review-queue" && $category === SourceDriftCategory::I
            && $reason === "stable_identity_material_or_structural_evidence_changed"
            && $before === self::OLD_QUEUE && $after === self::NEW_QUEUE;
    }

    /** @return array<string,mixed> */
    private static function queueObservation(bool $candidate): array
    {
        return [
            "domain" => "classifications", "source_type" => "v1.classification_review_vector",
            "source_id" => "classification-review-queue",
            "payload_hash" => $candidate ? self::NEW_QUEUE : self::OLD_QUEUE,
            "shape_hash" => self::QUEUE_SHAPE, "semantic_class" => "reviewed_current_v1_shape",
            "structural" => true, "state" => "ordinary",
        ];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            "disposition_id" => "final-classification-review-queue-20260923-v1",
            "mechanical_category" => "I", "effective_category" => "C",
            "reviewed_disposition" => "CONTRACT COMPATIBLE WITH PRESERVATION",
            "reason_code" => "reviewed_exact_classification_queue_preservation",
            "approval_sha256" => "775620017769bc07c9f56f41568df1432659d309839b1304cee9cdaa836944f2",
            "review_evidence_sha256" => "b61430be6412da5452de4fcf6775d175f9ecf3883b8bcd3692a0a8f4315732a1",
            "population_evidence_sha256" => "564dcc0c0fe43208dc7988d33f4a15f3ada7be6926af00ebd842855d6dca8a21",
            "mapper_comparison_sha256" => "bb926171e05b00d698b29ec53c20abb98dacbdab997040e1ef4ac4bfdc7e1d12",
            "exact_metadata_evidence_sha256" => "59e0f4b44d084cc32501590138624f9dc86fb834e7f2677c54f3195201c09408",
            "reference_package_digest" => self::REFERENCE_PACKAGE,
            "candidate_package_digest" => self::FINAL_PACKAGE,
            "reference_observation" => self::queueObservation(false),
            "candidate_observation" => self::queueObservation(true),
            "unchanged_classification_observations" => [
                "count" => 1208, "sha256" => self::OTHER_CLASSIFICATIONS,
                "classification_definition_count" => 65, "carrier_definition_count" => 3,
                "definitions_with_carriers_sha256" => "98a68e9def674f069c39f5df39fe2f1318a1c08456aebaa2492adc56af25d7c4",
                "alias_rule_count" => 7, "alias_aggregate_count" => 1,
                "alias_observation_sha256" => "01538f9d3d864964c46f4edc68a12bc0e88b1da71068f208619ca1f2b836a5f2",
                "book_assignment_count" => 1139,
                "book_assignment_observations_sha256" => "f642e66c57993a2e03ebdc7a20ee050e9d3e9ea366a82097b6b7fd61605896fc",
            ],
            // Reviewed counts/file hashes are bound by the full payload and package manifest;
            // snapshot observations themselves contain neither raw queue rows nor file metadata.
            "reviewed_queue_population" => [
                "reference_count" => 446, "candidate_count" => 450,
                "unchanged_existing" => 442, "updated_existing" => 4, "added" => 4, "removed" => 0,
                "existing_status_or_resolution_changes" => 0, "changed_existing_positions" => 419,
                "reference_file_sha256" => "1a1a742bee1a30f162f72a370dfafc9162c4afbffab7a504d41a39672e89ba2c",
                "candidate_file_sha256" => "0af964c1938cf3af1e63c492e531ee949aa059c878ef1312c229d251e1ca862a",
            ],
            "preservation" => [
                "required" => true, "source_path" => "data/taxonomy_review_queue.json",
                "scope" => "complete_immutable_final_queue_and_separate_historical_reference",
                "existing_mapper_reason" => "taxonomy_review_queue_preserved",
                "context_is_book_identity" => false, "new_typed_preservation_plans" => false,
            ],
            "classification_mapper_semantics_changed" => false,
            "reading_round_disposition_included" => false,
        ];
    }
}
