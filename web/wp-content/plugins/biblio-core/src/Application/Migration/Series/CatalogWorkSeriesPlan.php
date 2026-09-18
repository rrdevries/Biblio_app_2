<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Series;

use Biblio\Core\Application\Migration\Runner\TypedMigrationPlan;
use Biblio\Core\Application\Migration\Preservation\PreservedSourceEvidencePlan;
use Biblio\Core\Catalog\SeriesPosition;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\IdentifierConstraints;

final readonly class CatalogWorkSeriesPlan implements TypedMigrationPlan
{
    public function __construct(
        private string $workSourceId,
        private string $seriesSourceId,
        private SeriesPosition $position,
        private string $mappingContract,
        private ?PreservedSourceEvidencePlan $promotedPriorPreservation = null
    ) {
        IdentifierConstraints::assertValid($this->workSourceId, "Series Work source ID");
        IdentifierConstraints::assertValid($this->seriesSourceId, "Series source ID");
        if (trim($this->mappingContract) === "" || mb_strlen($this->mappingContract) > 191) {
            throw new ValidationException("Series mapping contract is invalid.");
        }
    }

    public function workSourceId(): string { return $this->workSourceId; }
    public function seriesSourceId(): string { return $this->seriesSourceId; }
    public function position(): SeriesPosition { return $this->position; }
    public function mappingContract(): string { return $this->mappingContract; }
    public function promotedPriorPreservation(): ?PreservedSourceEvidencePlan
    {
        return $this->promotedPriorPreservation;
    }

    public function canonicalPayload(): array
    {
        return [
            "target_kind" => "catalog_work_series",
            "work_source_id" => $this->workSourceId,
            "series_source_id" => $this->seriesSourceId,
            "position" => $this->position->value(),
            "mapping_contract" => $this->mappingContract,
            ...($this->promotedPriorPreservation === null ? [] : [
                "promoted_prior_preservation" =>
                    $this->promotedPriorPreservation->canonicalPayload(),
            ]),
        ];
    }
}
