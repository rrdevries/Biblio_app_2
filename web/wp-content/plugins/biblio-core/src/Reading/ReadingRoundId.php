<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Identity\IdentifierConstraints;

final readonly class ReadingRoundId
{
    public function __construct(private string $value)
    {
        IdentifierConstraints::assertValid($this->value, "Reading Round ID");
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
