<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Identity\IdentifierConstraints;

final readonly class LibraryBookTypeId
{
    public function __construct(private string $value)
    {
        IdentifierConstraints::assertValid($this->value, "Library Book Type ID");
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
