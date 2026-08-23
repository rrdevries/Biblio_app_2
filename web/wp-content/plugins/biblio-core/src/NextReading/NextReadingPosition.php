<?php

declare(strict_types=1);

namespace Biblio\Core\NextReading;

use Biblio\Core\Exception\ValidationException;

final readonly class NextReadingPosition
{
    public function __construct(private int $value)
    {
        if ($value < 1) {
            throw new ValidationException("Next Reading position must be positive.");
        }
    }

    public function value(): int { return $this->value; }
}
