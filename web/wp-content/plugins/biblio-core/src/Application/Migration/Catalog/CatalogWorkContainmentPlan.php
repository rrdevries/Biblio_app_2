<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Catalog;

use Biblio\Core\Application\Migration\Runner\TypedMigrationPlan;
use Biblio\Core\Catalog\ContainmentPosition;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\IdentifierConstraints;

final readonly class CatalogWorkContainmentPlan implements TypedMigrationPlan
{
    public function __construct(
        private string $parentWorkSourceId,
        private string $childWorkSourceId,
        private ContainmentPosition $position,
        private string $mappingContract
    ) {
        IdentifierConstraints::assertValid(
            $this->parentWorkSourceId,
            "Containment parent Work source ID"
        );
        IdentifierConstraints::assertValid(
            $this->childWorkSourceId,
            "Containment child Work source ID"
        );
        if ($this->parentWorkSourceId === $this->childWorkSourceId) {
            throw new ValidationException(
                "Containment parent and child source identities must differ."
            );
        }
        if (
            trim($this->mappingContract) === ""
            || mb_strlen($this->mappingContract) > 191
        ) {
            throw new ValidationException(
                "Containment mapping contract is invalid."
            );
        }
    }

    public function parentWorkSourceId(): string
    {
        return $this->parentWorkSourceId;
    }

    public function childWorkSourceId(): string
    {
        return $this->childWorkSourceId;
    }

    public function position(): ContainmentPosition
    {
        return $this->position;
    }

    public function mappingContract(): string
    {
        return $this->mappingContract;
    }

    public function canonicalPayload(): array
    {
        return [
            "target_kind" => "catalog_work_containment",
            "parent_work_source_id" => $this->parentWorkSourceId,
            "child_work_source_id" => $this->childWorkSourceId,
            "position" => $this->position->value(),
            "mapping_contract" => $this->mappingContract,
        ];
    }
}
