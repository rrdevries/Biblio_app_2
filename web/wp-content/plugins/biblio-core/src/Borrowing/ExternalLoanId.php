<?php

declare(strict_types=1);

namespace Biblio\Core\Borrowing;

use InvalidArgumentException;

final readonly class ExternalLoanId
{
    public function __construct(private string $value)
    {
        if (trim($this->value) === "") {
            throw new InvalidArgumentException(
                "External Loan ID must not be empty."
            );
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
