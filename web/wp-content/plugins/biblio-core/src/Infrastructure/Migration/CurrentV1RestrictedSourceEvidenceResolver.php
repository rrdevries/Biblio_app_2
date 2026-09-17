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
            || $plan->evidenceType() !== "current_v1_reflection"
            || $plan->sourceFile() !== "data/books.json"
            || $plan->sourceCollection() !== "books"
            || $plan->sourceField() !== "reflection"
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
        $body = $matches[0][$plan->sourceField()] ?? null;
        if (!is_string($body) || $body === "") {
            throw $this->failure();
        }
        $actual = DeterministicJson::hash([
            "source_slot" => $plan->sourceIdentity(),
            "body" => $body,
        ]);
        if (!hash_equals($plan->evidenceSha256(), $actual)) {
            throw $this->failure();
        }
    }

    private function failure(): MigrationRunnerFailure
    {
        return new MigrationRunnerFailure(
            MigrationRunnerReason::SourceChanged,
            "Restricted source evidence could not be verified against its package."
        );
    }
}
