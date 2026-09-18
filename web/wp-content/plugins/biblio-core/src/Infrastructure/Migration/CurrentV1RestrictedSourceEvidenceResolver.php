<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Preservation\PreservedSourceEvidencePlan;
use Biblio\Core\Application\Migration\Runner\{
    DeterministicJson,
    MigrationRunnerFailure,
    MigrationRunnerReason,
    MigrationSourcePackage
};
use JsonException;

/** Internal verifier only; it never returns or logs restricted source content. */
final class CurrentV1RestrictedSourceEvidenceResolver
{
    public function verify(
        MigrationSourcePackage $package,
        PreservedSourceEvidencePlan $plan
    ): void {
        if (
            $plan->adapterId() !== CurrentV1SourceAdapter::ADAPTER_ID
            || $plan->sourceFamily() !== CurrentV1SourceAdapter::SOURCE_FAMILY
            || $plan->sourceVersion() !== CurrentV1SourceAdapter::SOURCE_VERSION
            || !hash_equals($package->manifestDigest(), $plan->manifestSha256())
            || $plan->sourceFile() !== "data/books.json"
            || !in_array(
                $plan->sourceCollection(),
                ["books", "wishlistItems"],
                true
            )
        ) {
            throw $this->failure();
        }

        try {
            $document = json_decode(
                $package->read($plan->sourceFile()),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw $this->failure();
        }
        $rows = is_array($document) ? ($document[$plan->sourceCollection()] ?? null) : null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw $this->failure();
        }
        $matches = [];
        foreach ($rows as $row) {
            if (is_array($row) && ($row["id"] ?? null) === $plan->sourceEntityId()) {
                $matches[] = $row;
            }
        }
        if (count($matches) !== 1) {
            throw $this->failure();
        }
        $book = $matches[0];
        $actual = $this->evidenceEnvelope($plan, $book);
        if ($actual === null) {
            throw $this->failure();
        }
        if (!hash_equals($plan->evidenceSha256(), DeterministicJson::hash($actual))) {
            throw $this->failure();
        }
    }

    /**
     * @param array<string, mixed> $book
     * @return array<string, mixed>|null
     */
    private function evidenceEnvelope(PreservedSourceEvidencePlan $plan, array $book): ?array
    {
        if (
            $plan->evidenceType() === "current_v1_wishlist_auxiliary"
            && $plan->reasonCode() === "wishlist_auxiliary_evidence_preserved"
            && $plan->sourceCollection() === "wishlistItems"
            && $plan->sourceField() === "auxiliaryEvidence"
            && $plan->sourceIdentity() === CurrentV1SourceAdapter::WISHLIST_ITEM
                . "/" . $plan->sourceEntityId() . "/auxiliary"
            && ($book["id"] ?? null) === $plan->sourceEntityId()
            && ($book["type"] ?? null) === "edition"
            && is_string($book["titleGroupKey"] ?? null)
            && $book["titleGroupKey"] !== ""
            && is_string($book["desiredCarrier"] ?? null)
        ) {
            return [
                "source_slot" => "wishlistItems",
                "raw_type" => $book["type"],
                "title_group_key" => $book["titleGroupKey"],
                "desired_carrier" => $book["desiredCarrier"],
            ];
        }

        if ($plan->sourceCollection() !== "books") {
            return null;
        }

        if (
            $plan->evidenceType() === "current_v1_reflection"
            && $plan->reasonCode() === "reflection_target_not_available"
            && $plan->sourceField() === "reflection"
        ) {
            $body = $book["reflection"] ?? null;
            return is_string($body) && $body !== "" ? [
                "source_slot" => $plan->sourceIdentity(),
                "body" => $body,
            ] : null;
        }

        if (
            $plan->evidenceType() === "current_v1_series_membership_without_name"
            && $plan->reasonCode() === "series_name_missing"
            && $plan->sourceField() === "seriesName"
            && $plan->sourceIdentity() === CurrentV1SeriesSourceIds::membership($plan->sourceEntityId())
            && is_bool($book["series"] ?? null)
            && is_string($book["seriesName"] ?? null)
            && is_string($book["seriesNumber"] ?? null)
            && $book["seriesName"] === ""
        ) {
            return [
                "source_slot" => $plan->sourceIdentity(),
                "series" => $book["series"],
                "series_name" => $book["seriesName"],
                "series_number" => $book["seriesNumber"],
            ];
        }

        if (
            $plan->evidenceType() === "current_v1_series_position"
            && $plan->reasonCode() === "series_position_not_safely_mappable"
            && $plan->sourceField() === "seriesNumber"
            && $plan->sourceIdentity() === CurrentV1SeriesSourceIds::unsafePosition($plan->sourceEntityId())
            && is_string($book["seriesName"] ?? null)
            && $book["seriesName"] !== ""
            && is_string($book["seriesNumber"] ?? null)
            && $book["seriesNumber"] !== ""
        ) {
            return [
                "source_slot" => $plan->sourceIdentity(),
                "series_name" => $book["seriesName"],
                "series_number" => $book["seriesNumber"],
            ];
        }

        if (
            $plan->evidenceType() !== "current_v1_contained_work_series"
            || $plan->reasonCode() !== "contained_work_series_deferred"
            || $plan->sourceField() !== "containedWorks"
            || preg_match(
                '/\/contained-work\/([1-9][0-9]*)\/series$/D',
                $plan->sourceIdentity(),
                $matches
            ) !== 1
        ) {
            return null;
        }
        $slot = (int) $matches[1];
        if ($plan->sourceIdentity() !== CurrentV1SeriesSourceIds::contained($plan->sourceEntityId(), $slot)) {
            return null;
        }
        $containedWorks = $book["containedWorks"] ?? null;
        $contained = is_array($containedWorks) && array_is_list($containedWorks)
            ? ($containedWorks[$slot - 1] ?? null)
            : null;
        if (
            !is_array($contained)
            || !is_string($contained["series"] ?? null)
            || !is_string($contained["seriesIndex"] ?? null)
            || ($contained["series"] === "" && $contained["seriesIndex"] === "")
        ) {
            return null;
        }
        return [
            "source_slot" => $plan->sourceIdentity(),
            "one_based_slot" => $slot,
            "series" => $contained["series"],
            "series_index" => $contained["seriesIndex"],
        ];
    }

    private function failure(): MigrationRunnerFailure
    {
        return new MigrationRunnerFailure(
            MigrationRunnerReason::SourceChanged,
            "Restricted source evidence could not be verified against its package."
        );
    }
}
