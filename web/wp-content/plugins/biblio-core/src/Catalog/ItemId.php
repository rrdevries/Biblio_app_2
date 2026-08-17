<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Identity\IdentifierConstraints;

final readonly class ItemId
{
    public function __construct(private string $value)
    {
        IdentifierConstraints::assertValid($this->value, "Item ID");
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
