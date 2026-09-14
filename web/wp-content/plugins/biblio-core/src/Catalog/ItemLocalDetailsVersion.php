<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class ItemLocalDetailsVersion
{
    public function __construct(private int $value)
    {
        if ($this->value < 1) {
            throw new ValidationException("Item-local details version must be positive.");
        }
    }

    public function value(): int { return $this->value; }
    public function next(): self { return new self($this->value + 1); }
    public function equals(self $other): bool { return $this->value === $other->value; }
}
