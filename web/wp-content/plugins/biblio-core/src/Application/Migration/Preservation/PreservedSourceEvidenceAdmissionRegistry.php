<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Preservation;

use Biblio\Core\Exception\ValidationException;

final readonly class PreservedSourceEvidenceAdmissionRegistry
{
    /** @var array<string, PreservedSourceEvidenceAdmission> */
    private array $admissions;

    /** @param list<PreservedSourceEvidenceAdmission> $admissions */
    public function __construct(array $admissions)
    {
        $indexed = [];
        foreach ($admissions as $admission) {
            $key = $admission->evidenceType() . "\0" . $admission->reasonCode();
            if (isset($indexed[$key])) {
                throw new ValidationException("Duplicate preserved evidence admission.");
            }
            $indexed[$key] = $admission;
        }
        $this->admissions = $indexed;
    }

    public function assertAdmitted(PreservedSourceEvidencePlan $plan): void
    {
        $key = $plan->evidenceType() . "\0" . $plan->reasonCode();
        $admission = $this->admissions[$key] ?? null;
        if (
            !$admission instanceof PreservedSourceEvidenceAdmission
            || $admission->privacy() !== $plan->privacy()
        ) {
            throw new PreservedSourceEvidenceMigrationFailure(
                "preservation_plan_not_admitted",
                "Preserved source evidence is not admitted by the reviewed contract."
            );
        }
    }
}
