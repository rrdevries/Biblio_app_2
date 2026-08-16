<?php

declare(strict_types=1);

namespace Biblio\Core\Identity;

final readonly class UserId
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
