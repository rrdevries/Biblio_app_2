<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

use Biblio\Core\Identity\IdentifierConstraints;

final readonly class CollectionId
{
    public function __construct(private string $value)
    {
        IdentifierConstraints::assertValid($this->value, "Collection ID");
    }

    public function value(): string { return $this->value; }
    public function equals(self $other): bool { return $this->value === $other->value; }
}
