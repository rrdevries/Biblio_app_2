<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Exception\ValidationException;

final readonly class ClassificationTermName
{
    public function __construct(private string $value)
    {
        if (preg_match('//u', $this->value) !== 1) {
            throw new ValidationException(
                "Classification term name must be valid UTF-8."
            );
        }

        if (preg_match('/^\s*$/u', $this->value) === 1) {
            throw new ValidationException(
                "Classification term name must not be empty."
            );
        }
    }

    public function value(): string
    {
        return $this->value;
    }
}
