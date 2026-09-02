<?php

declare(strict_types=1);

namespace Biblio\Core\NextReading;

use Biblio\Core\Identity\IdentifierConstraints;

final readonly class NextReadingUndoToken
{
    public function __construct(private string $value)
    {
        IdentifierConstraints::assertValid($value, "Next Reading Undo token");
    }

    public function value(): string { return $this->value; }
}
