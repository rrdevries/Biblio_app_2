<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Catalog;

use Biblio\Core\Application\Migration\Runner\TypedMigrationPlan;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;

final readonly class CatalogWorkPlan implements TypedMigrationPlan
{
    public function __construct(
        private string $title,
        private ?WorkId $approvedExistingWorkId = null
    ) {
        // Reuse the canonical Work validation without creating product state.
        new Work(new WorkId("work-plan-validation"), $this->title);
    }

    public function title(): string { return $this->title; }
    public function approvedExistingWorkId(): ?WorkId
    {
        return $this->approvedExistingWorkId;
    }

    public function canonicalPayload(): array
    {
        return [
            "target_kind" => "catalog_work",
            "title" => $this->title,
            "approved_existing_work_id" =>
                $this->approvedExistingWorkId?->value(),
        ];
    }
}
