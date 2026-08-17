<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Exception\ValidationException;

final readonly class ReadingRoundId
{
    public function __construct(private string $value)
    {
        if (trim($this->value) === "") {
            throw new ValidationException(
                "Reading Round ID must not be empty."
            );
        }
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
