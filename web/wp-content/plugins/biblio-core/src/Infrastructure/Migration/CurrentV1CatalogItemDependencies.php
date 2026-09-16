<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\ItemLocalDetailsState;
use Biblio\Core\Exception\ValidationException;

final readonly class CurrentV1CatalogItemDependencies
{
    public function __construct(
        private ?LibraryCatalogSelection $classification,
        private bool $itemLocalReviewed,
        private ?ItemLocalDetailsState $localDetails = null,
        private ?CurrentV1ItemEligibility $itemEligibility = null,
        private ?\Biblio\Core\Application\Migration\Catalog\CatalogItemPreservationPlan
            $preservation = null
    ) {
        if (!$this->itemLocalReviewed && $this->localDetails !== null) {
            throw new ValidationException(
                "Unreviewed Item-local dependency cannot contain mapped details."
            );
        }
        if (!$this->itemLocalReviewed && $this->itemEligibility !== null) {
            throw new ValidationException(
                "Unreviewed Item-local dependency cannot contain eligibility."
            );
        }
        if (
            $this->itemEligibility !== CurrentV1ItemEligibility::ItemEligible
            && ($this->localDetails !== null || $this->preservation !== null)
        ) {
            throw new ValidationException(
                "A terminal CURRENT Copy cannot contain an Item dependency."
            );
        }
    }

    public static function unresolved(): self
    {
        return new self(null, false);
    }

    public static function reviewed(
        LibraryCatalogSelection $classification,
        ?ItemLocalDetailsState $localDetails
    ): self {
        return new self(
            $classification,
            true,
            $localDetails,
            CurrentV1ItemEligibility::ItemEligible
        );
    }

    public function withClassification(?LibraryCatalogSelection $classification): self
    {
        return new self(
            $classification,
            $this->itemLocalReviewed,
            $this->localDetails,
            $this->itemEligibility,
            $this->preservation
        );
    }

    public function withItemLocal(CurrentV1ItemLocalMapping $mapping): self
    {
        return new self(
            $this->classification,
            true,
            $mapping->localDetails(),
            $mapping->eligibility(),
            $mapping->preservation()
        );
    }

    public function classification(): ?LibraryCatalogSelection
    {
        return $this->classification;
    }

    public function itemLocalReviewed(): bool { return $this->itemLocalReviewed; }
    public function localDetails(): ?ItemLocalDetailsState { return $this->localDetails; }
    public function itemEligibility(): ?CurrentV1ItemEligibility
    {
        return $this->itemEligibility;
    }
    public function preservation():
        ?\Biblio\Core\Application\Migration\Catalog\CatalogItemPreservationPlan
    {
        return $this->preservation;
    }
}
