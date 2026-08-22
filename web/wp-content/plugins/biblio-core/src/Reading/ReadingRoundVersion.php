<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Exception\ValidationException;

final readonly class ReadingRoundVersion
{
    public function __construct(private int $value)
    {
        if ($this->value < 1) {
            throw new ValidationException(
                "Reading Round version must be a positive integer."
            );
        }
    }

    public static function initial(): self
    {
        return new self(1);
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }

    public function value(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
