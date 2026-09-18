<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Catalog\CatalogItemPreservationPlan;
use Biblio\Core\Catalog\ItemLocalDetailsState;
use Biblio\Core\Exception\ValidationException;

final readonly class CurrentV1ItemLocalMapping
{
    public function __construct(
        private CurrentV1ItemEligibility $eligibility,
        private ?ItemLocalDetailsState $localDetails,
        private ?CatalogItemPreservationPlan $preservation = null
    ) {
        if (
            $this->eligibility !== CurrentV1ItemEligibility::ItemEligible
            && ($this->localDetails !== null || $this->preservation !== null)
        ) {
            throw new ValidationException(
                "A terminal CURRENT Copy cannot contain an Item plan."
            );
        }
        if ($this->localDetails?->isUnknown() === true) {
            throw new ValidationException(
                "Reviewed Item-local absence must be represented by null."
            );
        }
    }

    public function eligibility(): CurrentV1ItemEligibility
    {
        return $this->eligibility;
    }

    public function isItemEligible(): bool
    {
        return $this->eligibility === CurrentV1ItemEligibility::ItemEligible;
    }

    public function localDetails(): ?ItemLocalDetailsState
    {
        return $this->localDetails;
    }

    public function preservation(): ?CatalogItemPreservationPlan
    {
        return $this->preservation;
    }

    public function preservationRequired(): bool
    {
        return $this->preservation !== null
            || $this->eligibility !== CurrentV1ItemEligibility::ItemEligible;
    }

    public function preservationReason(): ?CurrentV1ItemLocalMappingReason
    {
        if ($this->preservation !== null) {
            return CurrentV1ItemLocalMappingReason::CopyAuxiliaryEvidencePreserved;
        }

        return match ($this->eligibility) {
            CurrentV1ItemEligibility::NotLibraryItemExternalBorrowed =>
                CurrentV1ItemLocalMappingReason::ExternalBorrowedCopyPreserved,
            CurrentV1ItemEligibility::NotLibraryItemErroneousLegacyCopy =>
                CurrentV1ItemLocalMappingReason::ErroneousLegacyCopyNotCarriedForward,
            CurrentV1ItemEligibility::InvalidItemLocalAcquisitionDate =>
                CurrentV1ItemLocalMappingReason::InvalidAcquisitionDate,
            CurrentV1ItemEligibility::ItemEligible => null,
        };
    }
}
