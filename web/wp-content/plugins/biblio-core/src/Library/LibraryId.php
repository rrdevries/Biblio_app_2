<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

use InvalidArgumentException;

final readonly class LibraryId
{
    public function __construct(private string $value)
    {
        if (trim($this->value) === "") {
            throw new InvalidArgumentException(
                "Library ID must not be empty."
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
