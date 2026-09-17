<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Series;

use Biblio\Core\Application\Migration\Runner\TypedMigrationPlan;
use Biblio\Core\Catalog\{Series, SeriesId};
use Biblio\Core\Exception\ValidationException;

final readonly class CatalogSeriesPlan implements TypedMigrationPlan
{
    public function __construct(
        private string $displayName,
        private string $mappingContract
    ) {
        new Series(new SeriesId("series-plan-validation"), $this->displayName);
        if (trim($this->mappingContract) === "" || mb_strlen($this->mappingContract) > 191) {
            throw new ValidationException("Series mapping contract is invalid.");
        }
    }

    public function displayName(): string { return $this->displayName; }
    public function mappingContract(): string { return $this->mappingContract; }

    public function canonicalPayload(): array
    {
        return [
            "target_kind" => "catalog_series",
            "display_name" => $this->displayName,
            "mapping_contract" => $this->mappingContract,
        ];
    }
}
