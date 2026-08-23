<?php

declare(strict_types=1);

namespace Biblio\Core\NextReading;

use Biblio\Core\Exception\ValidationException;

final readonly class NextReadingListVersion
{
    public function __construct(private int $value)
    {
        if ($value < 1) {
            throw new ValidationException("Next Reading List version must be positive.");
        }
    }

    public static function initial(): self { return new self(1); }
    public function value(): int { return $this->value; }
    public function next(): self { return new self($this->value + 1); }
    public function equals(self $other): bool { return $this->value === $other->value; }
}
