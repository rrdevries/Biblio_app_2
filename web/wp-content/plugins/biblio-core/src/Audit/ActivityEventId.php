<?php

declare(strict_types=1);

namespace Biblio\Core\Audit;

use Biblio\Core\Identity\IdentifierConstraints;

final readonly class ActivityEventId
{
    public function __construct(private string $value)
    {
        IdentifierConstraints::assertValid($this->value, "Activity Event ID");
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
