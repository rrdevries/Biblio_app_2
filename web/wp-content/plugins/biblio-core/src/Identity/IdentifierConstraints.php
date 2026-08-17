<?php

declare(strict_types=1);

namespace Biblio\Core\Identity;

use Biblio\Core\Exception\ValidationException;

final class IdentifierConstraints
{
    public const MAX_LENGTH = 191;

    public static function assertValid(string $value, string $label): void
    {
        if (trim($value) === "") {
            throw new ValidationException("{$label} must not be empty.");
        }

        $length = preg_match_all('/./us', $value);

        if ($length === false) {
            throw new ValidationException("{$label} must be valid UTF-8.");
        }

        if ($length > self::MAX_LENGTH) {
            throw new ValidationException(
                "{$label} must not exceed " . self::MAX_LENGTH . " characters."
            );
        }
    }

    private function __construct()
    {
    }
}
