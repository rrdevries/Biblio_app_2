<?php

declare(strict_types=1);

namespace Biblio\Core\Audit;

use Biblio\Core\Identity\IdentifierConstraints;

final readonly class ActivityEventSource
{
    public function __construct(private string $value)
    {
        IdentifierConstraints::assertValid($this->value, "Activity Event source");
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
