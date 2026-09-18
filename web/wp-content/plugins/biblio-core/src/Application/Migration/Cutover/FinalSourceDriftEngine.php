<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

final readonly class FinalSourceDriftEngine
{
    /** @var list<string> */
    private const STRUCTURAL_DOMAINS = [
        "authors_contributors",
        "contained_works",
        "ratings",
        "written_reviews",
        "reflections",
        "series",
    ];

    /** @var list<string> */
    private const IDENTITY_DOMAINS = ["catalog_identity", "series"];

    /** @var list<string> */
    private const KNOWN_VIRTUAL_TYPES = [
        "v1.classification_vector",
        "v1.classification_definition",
        "v1.classification_review_vector",
        "v1.contained_work_vector",
        "v1.contributor_vector",
        "v1.erroneous_copy_candidate",
        "v1.item_local_evidence",
        "v1.isbn_group",
        "v1.rating_slot",
        "v1.reading_truth_evidence",
        "v1.reflection_slot",
        "v1.review_slot",
        "v1.semantic_value",
        "v1.series_group",
    ];

    public function compare(
        FinalSourceSnapshot $reference,
        FinalSourceSnapshot $candidate
    ): FinalSourceCompatibilityReport {
        $before = $this->index($reference->observations());
        $after = $this->index($candidate->observations());
        $knownTypes = array_fill_keys(array_keys($reference->sourceTypeCounts()), true);
        foreach (self::KNOWN_VIRTUAL_TYPES as $knownVirtualType) {
            $knownTypes[$knownVirtualType] = true;
        }
        $knownShapes = [];
        $knownSemantics = [];
        foreach ($reference->observations() as $observation) {
            $knownTypes[$observation->sourceType()] = true;
            $knownShapes[$observation->domain()][$observation->shapeHash()] = true;
            $knownSemantics[$observation->domain()][$observation->semanticClass()] = true;
        }

        $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
        sort($keys, SORT_STRING);
        $drift = [];
        foreach ($keys as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if (!$old instanceof FinalSourceObservation && $new instanceof FinalSourceObservation) {
                [$category, $reason] = $this->added($new, $knownTypes, $knownShapes, $knownSemantics);
                $drift[] = $this->item($new, $category, $reason, null, $new->payloadHash());
                continue;
            }
            if ($old instanceof FinalSourceObservation && !$new instanceof FinalSourceObservation) {
                $drift[] = $this->item(
                    $old,
                    SourceDriftCategory::H,
                    "source_identity_disappeared",
                    $old->payloadHash(),
                    null
                );
                continue;
            }
            if (hash_equals($old->payloadHash(), $new->payloadHash())) {
                $drift[] = $this->item(
                    $new,
                    SourceDriftCategory::A,
                    "no_relevant_change",
                    $old->payloadHash(),
                    $new->payloadHash()
                );
                continue;
            }
            [$category, $reason] = $this->changed($old, $new);
            $drift[] = $this->item(
                $new,
                $category,
                $reason,
                $old->payloadHash(),
                $new->payloadHash()
            );
        }

        return new FinalSourceCompatibilityReport($reference, $candidate, $drift);
    }

    /**
     * @param array<string,bool> $knownTypes
     * @param array<string,array<string,bool>> $knownShapes
     * @param array<string,array<string,bool>> $knownSemantics
     * @return array{SourceDriftCategory,string}
     */
    private function added(
        FinalSourceObservation $item,
        array $knownTypes,
        array $knownShapes,
        array $knownSemantics
    ): array {
        if (!isset($knownTypes[$item->sourceType()])) {
            return [SourceDriftCategory::J, "unsupported_new_source_type"];
        }
        if ($item->domain() === "source_semantics") {
            return [SourceDriftCategory::E, "new_raw_value_enum_or_source_shape"];
        }
        if ($item->state() === "conflict" || $item->state() === "quarantine_candidate") {
            return [SourceDriftCategory::G, "new_conflict_or_quarantine_condition"];
        }
        if (in_array(
            $item->domain(),
            ["catalog_identity", "series", "erroneous_copies"],
            true
        )) {
            return [SourceDriftCategory::F, "population_bound_identity_set_changed"];
        }
        if (
            !isset($knownShapes[$item->domain()][$item->shapeHash()])
            || !isset($knownSemantics[$item->domain()][$item->semanticClass()])
        ) {
            return [SourceDriftCategory::E, "new_raw_value_enum_or_source_shape"];
        }
        return [
            $item->structural() ? SourceDriftCategory::B : SourceDriftCategory::D,
            $item->structural()
                ? "supported_population_increase"
                : "supported_new_record_under_existing_semantics",
        ];
    }

    /** @return array{SourceDriftCategory,string} */
    private function changed(
        FinalSourceObservation $old,
        FinalSourceObservation $new
    ): array {
        if ($old->state() !== $new->state() && ($old->domain() === "circulation" || $new->state() === "conflict")) {
            return [
                $new->state() === "conflict" ? SourceDriftCategory::G : SourceDriftCategory::C,
                $new->state() === "conflict"
                    ? "new_or_changed_circulation_conflict"
                    : "circulation_conflict_resolved_under_existing_semantics",
            ];
        }
        if (
            $old->shapeHash() !== $new->shapeHash()
            || $old->semanticClass() !== $new->semanticClass()
        ) {
            return [SourceDriftCategory::E, "new_raw_value_enum_or_source_shape"];
        }
        if ($old->domain() === "erroneous_copies") {
            return [SourceDriftCategory::I, "reviewed_copy_exclusion_evidence_changed"];
        }
        if (in_array($old->domain(), self::IDENTITY_DOMAINS, true)) {
            return [SourceDriftCategory::F, "identity_grouping_or_provenance_contract_changed"];
        }
        if ($old->structural() || in_array($old->domain(), self::STRUCTURAL_DOMAINS, true)) {
            return [SourceDriftCategory::I, "stable_identity_material_or_structural_evidence_changed"];
        }
        return [
            SourceDriftCategory::C,
            "payload_change_covered_by_existing_mapping_semantics",
        ];
    }

    /**
     * @param list<FinalSourceObservation> $observations
     * @return array<string,FinalSourceObservation>
     */
    private function index(array $observations): array
    {
        $indexed = [];
        foreach ($observations as $observation) {
            $indexed[$observation->key()] = $observation;
        }
        return $indexed;
    }

    private function item(
        FinalSourceObservation $observation,
        SourceDriftCategory $category,
        string $reason,
        ?string $before,
        ?string $after
    ): SourceDrift {
        return new SourceDrift(
            $observation->domain(),
            $observation->sourceType(),
            $observation->sourceId(),
            $category,
            $reason,
            $before,
            $after
        );
    }
}
