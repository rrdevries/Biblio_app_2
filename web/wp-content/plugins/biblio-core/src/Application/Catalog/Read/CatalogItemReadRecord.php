<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemStatus;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Reading\PersonalWorkReadingStatus;

final readonly class CatalogItemReadRecord
{
    public function __construct(
        private ItemId $itemId,
        private WorkId $workId,
        private EditionId $editionId,
        private string $title,
        private ItemStatus $itemStatus,
        private int $activeRoundCount,
        private int $completedRoundCount,
        private int $stoppedRoundCount,
        private int $historicalCompletedRoundCount,
        private ?CatalogActiveReadingRoundView $activeReadingRound
    ) {
    }

    public function itemId(): ItemId { return $this->itemId; }
    public function workId(): WorkId { return $this->workId; }
    public function editionId(): EditionId { return $this->editionId; }
    public function title(): string { return $this->title; }
    public function itemStatus(): ItemStatus { return $this->itemStatus; }
    public function activeRoundCount(): int { return $this->activeRoundCount; }
    public function completedRoundCount(): int { return $this->completedRoundCount; }
    public function stoppedRoundCount(): int { return $this->stoppedRoundCount; }
    public function historicalCompletedRoundCount(): int
    {
        return $this->historicalCompletedRoundCount;
    }
    public function activeReadingRound(): ?CatalogActiveReadingRoundView
    {
        return $this->activeReadingRound;
    }
    public function hasActiveRoundForItem(): bool
    {
        return $this->activeReadingRound !== null;
    }

    public function readingStatus(): PersonalWorkReadingStatus
    {
        if ($this->activeRoundCount > 0) {
            return PersonalWorkReadingStatus::Reading;
        }

        if ($this->completedRoundCount > 0) {
            return PersonalWorkReadingStatus::Read;
        }

        return PersonalWorkReadingStatus::NotRead;
    }
}
