<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

/** Renée's single approved 2026-09-23 disposition; not a configurable exception registry. */
final readonly class ReviewedAuthorShapeDisposition
{
    private const BOOK_ID = "1771014295306";
    private const REFERENCE_PACKAGE = "d720dacf37a611761549525d33d186f64bd9b3ee9bf5bc4f68feb5edfd9caf69";
    private const FINAL_PACKAGE = "0ac55dbac2b98b4e2141573a94a3aa600d4cf3bdda8fc72a827574cf0cadac4a";
    private const OLD_PAYLOAD = "6e4a63b4ef007488a788ad4b7c1dc74a2e4b6cc5ae773a0a23193e6baaaa6f72";
    private const NEW_PAYLOAD = "d7228e61b0c367a78dce0fe5bdd1a8cfdbebc36ce97864df208f8294f7490c0a";
    private const OLD_SHAPE = "b955461a1b74ed184d83f00fbbf1a6a5d25365dbad580e10a3d51fff2f8d6710";
    private const NEW_SHAPE = "768ef09a41016085f7834f467585c01c7ce5e9af3d991315a38b441e11388c0d";
    private const CONTRIBUTOR_PAYLOAD = "4ba30f5f02b3d18cfd2895913bd9209dad78b9a8415bffc856d990dc1fb88c00";
    private const CONTRIBUTOR_SHAPE = "dcb452a982945e5e2957930d83d36af5ceee19805ec0c3b30529ae8f44f6e49e";

    private function __construct() {}

    public static function forSnapshots(
        FinalSourceSnapshot $reference,
        FinalSourceSnapshot $candidate
    ): ?self {
        // Package digest includes logical ID, archive, manifest, family, version and adapter.
        // The production snapshot builder verifies the manifest against actual inspected bytes.
        if (
            $reference->package()->digest() !== self::REFERENCE_PACKAGE
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
        $book = false;
        $contributors = false;
        foreach ($snapshot->observations() as $observation) {
            if ($observation->toArray() === self::bookObservation($candidate)) {
                $book = true;
            }
            if ($observation->toArray() === self::contributorObservation()) {
                $contributors = true;
            }
        }
        return $book && $contributors;
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
        return $domain === "catalog_books" && $sourceType === "v1.book"
            && $sourceId === self::BOOK_ID && $category === SourceDriftCategory::E
            && $reason === "new_raw_value_enum_or_source_shape"
            && $before === self::OLD_PAYLOAD && $after === self::NEW_PAYLOAD;
    }

    /** @return array<string,mixed> */
    private static function bookObservation(bool $candidate): array
    {
        return [
            "domain" => "catalog_books", "source_type" => "v1.book", "source_id" => self::BOOK_ID,
            "payload_hash" => $candidate ? self::NEW_PAYLOAD : self::OLD_PAYLOAD,
            "shape_hash" => $candidate ? self::NEW_SHAPE : self::OLD_SHAPE,
            "semantic_class" => "reviewed_current_v1_shape", "structural" => false, "state" => "ordinary",
        ];
    }

    /** @return array<string,mixed> */
    private static function contributorObservation(): array
    {
        return [
            "domain" => "authors_contributors", "source_type" => "v1.contributor_vector",
            "source_id" => "v1.book/" . self::BOOK_ID . "/contributors",
            "payload_hash" => self::CONTRIBUTOR_PAYLOAD, "shape_hash" => self::CONTRIBUTOR_SHAPE,
            "semantic_class" => "reviewed_current_v1_shape", "structural" => false, "state" => "ordinary",
        ];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            "disposition_id" => "final-author-shape-1771014295306-20260923-v1",
            "mechanical_category" => "E", "effective_category" => "C",
            "reason_code" => "reviewed_exact_author_default_materialization",
            "approval_sha256" => "f275997ccbdf7e0074705e091bb2f448fb1c261a758ab94c78d3b7888bc1a176",
            "review_evidence_sha256" => "7023ac1cad556f07de2f18152575b65c0287cd0fa2f4912862b9f92ea540273e",
            "population_evidence_sha256" => "a609f84764da9d2622992b84543111161ab5587fe20d2585b3363c6c0316e76f",
            "mapper_comparison_sha256" => "97db14a66b4acf209db419fa3e637458c459de195f03697312815a647a3eaab2",
            "reference_package_digest" => self::REFERENCE_PACKAGE,
            "candidate_package_digest" => self::FINAL_PACKAGE,
            "reference_observation" => self::bookObservation(false),
            "candidate_observation" => self::bookObservation(true),
            "unchanged_contributor_observation" => self::contributorObservation(),
            "field_transitions" => [
                "authorIds" => ["before" => "ABSENT", "after" => "EMPTY_ARRAY"],
                "authorsLocked" => ["before" => "ABSENT", "after" => "FALSE"],
            ],
            // Full raw payload pins preserve all fields, names/order and absent/null distinctions.
            "author_mapper_semantics_changed" => false,
            "new_preservation_required" => false,
        ];
    }
}
