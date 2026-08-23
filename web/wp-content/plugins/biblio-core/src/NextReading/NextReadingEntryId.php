<?php

declare(strict_types=1);

namespace Biblio\Core\NextReading;

use Biblio\Core\Identity\IdentifierConstraints;

final readonly class NextReadingEntryId
{
    public function __construct(private string $value)
    {
        IdentifierConstraints::assertValid($value, "Next Reading Entry ID");
    }

    public function value(): string { return $this->value; }
    public function equals(self $other): bool { return $this->value === $other->value; }
}
