<?php

declare(strict_types=1);

namespace Biblio\Core\Notes;

final readonly class PrivateNoteContent
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
