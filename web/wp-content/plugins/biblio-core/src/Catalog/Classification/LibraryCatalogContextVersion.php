<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Exception\ValidationException;

final readonly class LibraryCatalogContextVersion
{
    public function __construct(private int $value)
    {
        if ($this->value < 1) {
            throw new ValidationException(
                "Library Catalog Context version must be at least 1."
            );
        }
    }

    public static function initial(): self
    {
        return new self(1);
    }

    public function value(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
