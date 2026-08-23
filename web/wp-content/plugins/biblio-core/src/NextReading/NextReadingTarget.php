<?php

declare(strict_types=1);

namespace Biblio\Core\NextReading;

use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Library\LibraryId;

final readonly class NextReadingTarget
{
    private function __construct(
        private NextReadingTargetType $type,
        private WorkId $workId,
        private ?ItemId $itemIdSnapshot,
        private ?LibraryId $libraryIdSnapshot,
        private ?ExternalLoanId $externalLoanIdSnapshot,
        private ?ItemId $liveItemId,
        private ?ExternalLoanId $liveExternalLoanId
    ) {
    }

    public static function forWork(WorkId $workId): self
    {
        return new self(NextReadingTargetType::Work, $workId, null, null, null, null, null);
    }

    public static function forLibraryItem(
        WorkId $workId,
        ItemId $itemId,
        LibraryId $libraryId,
        bool $live = true
    ): self {
        return new self(
            NextReadingTargetType::LibraryItem,
            $workId,
            $itemId,
            $libraryId,
            null,
            $live ? $itemId : null,
            null
        );
    }

    public static function forExternalLoan(
        WorkId $workId,
        ExternalLoanId $externalLoanId,
        bool $live = true
    ): self {
        return new self(
            NextReadingTargetType::ExternalLoan,
            $workId,
            null,
            null,
            $externalLoanId,
            null,
            $live ? $externalLoanId : null
        );
    }

    public function type(): NextReadingTargetType { return $this->type; }
    public function workId(): WorkId { return $this->workId; }
    public function itemIdSnapshot(): ?ItemId { return $this->itemIdSnapshot; }
    public function libraryIdSnapshot(): ?LibraryId { return $this->libraryIdSnapshot; }
    public function externalLoanIdSnapshot(): ?ExternalLoanId { return $this->externalLoanIdSnapshot; }
    public function liveItemId(): ?ItemId { return $this->liveItemId; }
    public function liveExternalLoanId(): ?ExternalLoanId { return $this->liveExternalLoanId; }

    public function uniquenessKey(): string
    {
        return match ($this->type) {
            NextReadingTargetType::Work => "work:" . $this->workId->value(),
            NextReadingTargetType::LibraryItem => "item:" . $this->itemIdSnapshot?->value(),
            NextReadingTargetType::ExternalLoan => "external:" . $this->externalLoanIdSnapshot?->value(),
        };
    }
}
