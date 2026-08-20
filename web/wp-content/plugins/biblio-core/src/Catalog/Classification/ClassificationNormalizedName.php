<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Exception\ValidationException;

final readonly class ClassificationNormalizedName
{
    public function __construct(private string $value)
    {
        if (
            preg_match('//u', $this->value) !== 1
            || trim($this->value) === ""
        ) {
            throw new ValidationException(
                "Normalized classification name must be non-empty valid UTF-8."
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
