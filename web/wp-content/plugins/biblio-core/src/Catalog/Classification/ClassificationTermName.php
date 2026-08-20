<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Exception\ValidationException;

final readonly class ClassificationTermName
{
    public const MAX_LENGTH = 512;

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

        if (preg_match_all('/./us', $this->value) > self::MAX_LENGTH) {
            throw new ValidationException(
                "Classification term name must not exceed "
                . self::MAX_LENGTH . " characters."
            );
        }
    }

    public function value(): string
    {
        return $this->value;
    }
}
