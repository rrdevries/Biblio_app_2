<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

use Biblio\Core\Exception\ValidationException;

final readonly class CollectionDescription
{
    public const MAX_LENGTH = 300;

    public function __construct(private string $value)
    {
        if (preg_match('//u', $value) !== 1) {
            throw new ValidationException("Collection description must be valid UTF-8.");
        }
        if (preg_match_all('/./us', $value) > self::MAX_LENGTH) {
            throw new ValidationException("Collection description must not exceed 300 characters.");
        }
    }

    public function value(): string { return $this->value; }
    public function equals(self $other): bool { return $this->value === $other->value; }
}
