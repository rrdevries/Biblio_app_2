<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use InvalidArgumentException;

final readonly class ItemId
{
    public function __construct(private string $value)
    {
        if (trim($this->value) === "") {
            throw new InvalidArgumentException("Item ID must not be empty.");
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
