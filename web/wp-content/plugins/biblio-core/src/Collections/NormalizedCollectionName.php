<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

use Biblio\Core\Exception\ValidationException;

final readonly class NormalizedCollectionName
{
    public function __construct(private string $value)
    {
        if (preg_match('//u', $value) !== 1 || trim($value) === '') {
            throw new ValidationException("Normalized Collection name must be non-empty valid UTF-8.");
        }
        if (preg_match_all('/./us', $value) > CollectionName::MAX_LENGTH) {
            throw new ValidationException("Normalized Collection name must not exceed 80 characters.");
        }
    }

    public function value(): string { return $this->value; }
    public function equals(self $other): bool { return $this->value === $other->value; }
}
