<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\ItemId;

final readonly class ReadingSource
{
    private function __construct(
        private ?ItemId $itemId,
        private ?ExternalLoanId $externalLoanId
    ) {
    }

    public static function libraryItem(ItemId $itemId): self
    {
        return new self($itemId, null);
    }

    public static function externalLoan(ExternalLoanId $externalLoanId): self
    {
        return new self(null, $externalLoanId);
    }

    public static function same(?self $left, ?self $right): bool
    {
        return $left === null
            ? $right === null
            : $right !== null && $left->equals($right);
    }

    public function itemId(): ?ItemId
    {
        return $this->itemId;
    }

    public function externalLoanId(): ?ExternalLoanId
    {
        return $this->externalLoanId;
    }

    public function equals(self $other): bool
    {
        if ($this->itemId !== null && $other->itemId !== null) {
            return $this->itemId->equals($other->itemId);
        }

        if (
            $this->externalLoanId !== null
            && $other->externalLoanId !== null
        ) {
            return $this->externalLoanId->equals($other->externalLoanId);
        }

        return false;
    }
}
