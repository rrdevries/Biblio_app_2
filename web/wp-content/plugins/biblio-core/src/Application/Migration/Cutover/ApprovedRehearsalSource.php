<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\Runner\{DeterministicJson, MigrationSourcePackageFactory};

/** Approval is supplied by an operator; PREP-01A never manufactures it. */
final readonly class ApprovedRehearsalSource
{
    private FinalSourcePlanningContext $planningContext;

    /** @param array<string,mixed> $review */
    public function __construct(
        public FinalSourceIntakeReceipt $intake,
        public FinalPopulationContractBundle $bundle,
        public array $review,
        string $archivePath
    ) {
        RehearsalContract::keys($review, [
            "state", "review_id", "package_digest", "bundle_digest", "source_profile_digest",
            "quarantine", "author_exceptions", "occurrence_exceptions", "copy_exclusions",
        ]);
        RehearsalContract::require($review["state"] === "approved_for_rehearsal", "source_not_approved");
        RehearsalContract::id($review["review_id"]);
        RehearsalContract::require(in_array($bundle->approvalState(), [
            FinalSourceApprovalState::MechanicallyCompatible,
            FinalSourceApprovalState::ReviewedCompatible,
        ], true), "contract_review_required");
        $planningReview = $review;
        unset($planningReview["state"], $planningReview["review_id"]);
        $this->planningContext = new FinalSourcePlanningContext(
            $intake, $bundle, $planningReview, $archivePath
        );
    }

    public function planningContext(): FinalSourcePlanningContext { return $this->planningContext; }

    public function verify(MigrationSourcePackageFactory $packages): void
    {
        $this->planningContext->verify($packages);
    }

    /** @return array<string,mixed> */
    public function provenance(): array
    {
        return [
            "package" => $this->intake->identity()->toArray(),
            "bundle_digest" => $this->bundle->digest(),
            "review_digest" => DeterministicJson::hash($this->review),
            "state" => "approved_for_rehearsal",
            "circulation_profile" => $this->bundle->toArray()["circulation_profile"],
        ];
    }
}
