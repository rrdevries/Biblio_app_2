<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Catalog;

use Biblio\Core\Application\Migration\Runner\TypedMigrationPlan;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;

final readonly class CatalogWorkPlan implements TypedMigrationPlan
{
    public function __construct(
        private string $title,
        private ?WorkId $approvedExistingWorkId = null,
        private ?string $aliasOfSourceId = null
    ) {
        // Reuse the canonical Work validation without creating product state.
        new Work(new WorkId("work-plan-validation"), $this->title);
        if (
            $this->aliasOfSourceId !== null
            && (
                trim($this->aliasOfSourceId) === ""
                || mb_strlen($this->aliasOfSourceId) > 191
            )
        ) {
            throw new ValidationException(
                "Work alias source reference is invalid."
            );
        }
        if ($this->approvedExistingWorkId !== null && $this->aliasOfSourceId !== null) {
            throw new ValidationException(
                "Work plan cannot combine an approved target with a source alias."
            );
        }
    }

    public function title(): string { return $this->title; }
    public function approvedExistingWorkId(): ?WorkId
    {
        return $this->approvedExistingWorkId;
    }
    public function aliasOfSourceId(): ?string { return $this->aliasOfSourceId; }

    public function canonicalPayload(): array
    {
        return [
            "target_kind" => "catalog_work",
            "title" => $this->title,
            "approved_existing_work_id" =>
                $this->approvedExistingWorkId?->value(),
            ...($this->aliasOfSourceId === null ? [] : [
                "alias_of_source_id" => $this->aliasOfSourceId,
            ]),
        ];
    }
}
