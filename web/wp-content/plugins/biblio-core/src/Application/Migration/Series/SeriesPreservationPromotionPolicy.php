<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Series;

use Biblio\Core\Application\Migration\MigrationRun;
use Biblio\Core\Application\Migration\Preservation\{
    PreservedSourceEvidencePlan,
    PreservedSourceEvidencePrivacy
};
use Biblio\Core\Application\Migration\SourceObservation;

/** Closed admission for one reviewed preservation-to-Series promotion lane. */
final readonly class SeriesPreservationPromotionPolicy
{
    public function __construct(
        private string $evidenceType,
        private string $reasonCode,
        private string $adapterId,
        private string $sourceFamily,
        private string $sourceVersion,
        private string $manifestSha256,
        private string $mappingContract,
        private string $sourceFile,
        private string $sourceCollection,
        private string $sourceField
    ) {
    }

    public function admits(
        PreservedSourceEvidencePlan $plan,
        SourceObservation $observation,
        MigrationRun $run
    ): bool {
        return $plan->sourceIdentity() === $observation->sourceId()
            && $plan->evidenceType() === $this->evidenceType
            && $plan->reasonCode() === $this->reasonCode
            && $plan->privacy() === PreservedSourceEvidencePrivacy::OrdinarySource
            && $plan->occurrenceCount() === 1
            && $plan->adapterId() === $this->adapterId
            && $plan->sourceFamily() === $this->sourceFamily
            && $plan->sourceVersion() === $this->sourceVersion
            && hash_equals($plan->manifestSha256(), $this->manifestSha256)
            && $plan->mappingContract() === $this->mappingContract
            && $plan->sourceFile() === $this->sourceFile
            && $plan->sourceCollection() === $this->sourceCollection
            && $plan->sourceField() === $this->sourceField
            && $run->sourceFamily() === $this->sourceFamily
            && $run->sourceVersion() === $this->sourceVersion
            && hash_equals($run->sourceSnapshot(), $this->manifestSha256)
            && $observation->sourceFamily() === $this->sourceFamily
            && hash_equals($observation->sourceSnapshot(), $this->manifestSha256);
    }
}
