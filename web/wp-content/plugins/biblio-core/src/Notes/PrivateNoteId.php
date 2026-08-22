<?php

declare(strict_types=1);

namespace Biblio\Core\Notes;

use Biblio\Core\Identity\IdentifierConstraints;

final readonly class PrivateNoteId
{
    public function __construct(private string $value)
    {
        IdentifierConstraints::assertValid($this->value, "Private Note ID");
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
