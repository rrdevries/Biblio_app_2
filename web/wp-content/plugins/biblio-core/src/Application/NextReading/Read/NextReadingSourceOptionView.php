<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading\Read;

use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\NextReading\PreferredReadingSourceType;

final readonly class NextReadingSourceOptionView
{
    private function __construct(
        private PreferredReadingSourceType $type,
        private ?LibraryId $libraryId,
        private ?ItemId $itemId,
        private ?ExternalLoanId $externalLoanId,
        private string $label
    ) {
    }

    public static function libraryItem(
        LibraryId $libraryId,
        ItemId $itemId,
        string $label
    ): self {
        return new self(
            PreferredReadingSourceType::LibraryItem,
            $libraryId,
            $itemId,
            null,
            $label
        );
    }

    public static function externalLoan(
        ExternalLoanId $externalLoanId,
        string $label
    ): self {
        return new self(
            PreferredReadingSourceType::ExternalLoan,
            null,
            null,
            $externalLoanId,
            $label
        );
    }

    public function type(): PreferredReadingSourceType { return $this->type; }
    public function libraryId(): ?LibraryId { return $this->libraryId; }
    public function itemId(): ?ItemId { return $this->itemId; }
    public function externalLoanId(): ?ExternalLoanId { return $this->externalLoanId; }
    public function label(): string { return $this->label; }
}
