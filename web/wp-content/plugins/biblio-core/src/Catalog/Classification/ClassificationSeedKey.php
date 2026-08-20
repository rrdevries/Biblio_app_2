<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\IdentifierConstraints;

final readonly class ClassificationSeedKey
{
    public function __construct(private string $value)
    {
        IdentifierConstraints::assertValid($this->value, "Classification seed key");

        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $this->value) !== 1) {
            throw new ValidationException(
                "Classification seed key must use lowercase dotted identifiers."
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
