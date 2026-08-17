<?php

declare(strict_types=1);

namespace Biblio\Core\Borrowing;

use Biblio\Core\Identity\IdentifierConstraints;

final readonly class ExternalLoanId
{
    public function __construct(private string $value)
    {
        IdentifierConstraints::assertValid($this->value, "External Loan ID");
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
