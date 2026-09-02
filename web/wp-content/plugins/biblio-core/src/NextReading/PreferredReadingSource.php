<?php

declare(strict_types=1);

namespace Biblio\Core\NextReading;

use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Reading\ReadingSource;

final readonly class PreferredReadingSource
{
    private function __construct(
        private PreferredReadingSourceType $type,
        private ?ItemId $itemIdSnapshot,
        private ?LibraryId $libraryIdSnapshot,
        private ?ExternalLoanId $externalLoanIdSnapshot,
        private ?ItemId $liveItemId,
        private ?ExternalLoanId $liveExternalLoanId
    ) {
    }

    public static function libraryItem(
        ItemId $itemId,
        LibraryId $libraryId,
        bool $live = true
    ): self {
        return new self(
            PreferredReadingSourceType::LibraryItem,
            $itemId,
            $libraryId,
            null,
            $live ? $itemId : null,
            null
        );
    }

    public static function externalLoan(
        ExternalLoanId $externalLoanId,
        bool $live = true
    ): self {
        return new self(
            PreferredReadingSourceType::ExternalLoan,
            null,
            null,
            $externalLoanId,
            null,
            $live ? $externalLoanId : null
        );
    }

    public function type(): PreferredReadingSourceType { return $this->type; }
    public function itemIdSnapshot(): ?ItemId { return $this->itemIdSnapshot; }
    public function libraryIdSnapshot(): ?LibraryId { return $this->libraryIdSnapshot; }
    public function externalLoanIdSnapshot(): ?ExternalLoanId { return $this->externalLoanIdSnapshot; }
    public function liveItemId(): ?ItemId { return $this->liveItemId; }
    public function liveExternalLoanId(): ?ExternalLoanId { return $this->liveExternalLoanId; }

    public function matchesLiveSource(ReadingSource $source): bool
    {
        return match ($this->type) {
            PreferredReadingSourceType::LibraryItem =>
                $this->liveItemId !== null
                && $source->itemId()?->equals($this->liveItemId) === true,
            PreferredReadingSourceType::ExternalLoan =>
                $this->liveExternalLoanId !== null
                && $source->externalLoanId()?->equals($this->liveExternalLoanId) === true,
        };
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type
            && $this->itemIdSnapshot?->value() === $other->itemIdSnapshot?->value()
            && $this->libraryIdSnapshot?->value() === $other->libraryIdSnapshot?->value()
            && $this->externalLoanIdSnapshot?->value() === $other->externalLoanIdSnapshot?->value()
            && $this->liveItemId?->value() === $other->liveItemId?->value()
            && $this->liveExternalLoanId?->value() === $other->liveExternalLoanId?->value();
    }
}
