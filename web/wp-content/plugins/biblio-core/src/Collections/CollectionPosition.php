<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

use Biblio\Core\Exception\ValidationException;

final readonly class CollectionPosition
{
    public function __construct(private int $value)
    {
        if ($value < 1) { throw new ValidationException("Collection position must be positive."); }
    }

    public function value(): int { return $this->value; }
    public function equals(self $other): bool { return $this->value === $other->value; }
}
