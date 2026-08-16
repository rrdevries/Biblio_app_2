<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

final readonly class LibraryId
{
    public function __construct(private string $value)
    {
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
