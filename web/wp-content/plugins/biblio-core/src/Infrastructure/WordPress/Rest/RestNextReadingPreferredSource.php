<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\NextReading\PreferredReadingSourceType;

final readonly class RestNextReadingPreferredSource
{
    private function __construct(
        private PreferredReadingSourceType $type,
        private ?LibraryId $libraryId,
        private ?ItemId $itemId,
        private ?ExternalLoanId $externalLoanId
    ) {
    }

    public static function libraryItem(LibraryId $libraryId, ItemId $itemId): self
    {
        return new self(
            PreferredReadingSourceType::LibraryItem,
            $libraryId,
            $itemId,
            null
        );
    }

    public static function externalLoan(ExternalLoanId $externalLoanId): self
    {
        return new self(
            PreferredReadingSourceType::ExternalLoan,
            null,
            null,
            $externalLoanId
        );
    }

    public function type(): PreferredReadingSourceType { return $this->type; }
    public function libraryId(): ?LibraryId { return $this->libraryId; }
    public function itemId(): ?ItemId { return $this->itemId; }
    public function externalLoanId(): ?ExternalLoanId { return $this->externalLoanId; }
}
