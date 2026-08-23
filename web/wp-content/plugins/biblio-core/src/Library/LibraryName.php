<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

use Biblio\Core\Exception\ValidationException;

final readonly class LibraryName
{
    public const MAX_LENGTH = 191;
    public const PERSONAL_DEFAULT = "Mijn Bibliotheek";

    private string $value;

    public function __construct(string $value)
    {
        if (!mb_check_encoding($value, "UTF-8")) {
            throw new ValidationException("Library name must be valid UTF-8.");
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        if ($normalized === null || $normalized === "") {
            throw new ValidationException("Library name must not be empty.");
        }

        if (mb_strlen($normalized, "UTF-8") > self::MAX_LENGTH) {
            throw new ValidationException(
                "Library name must not exceed " . self::MAX_LENGTH
                . " characters."
            );
        }

        $this->value = $normalized;
    }

    public static function personalDefault(): self
    {
        return new self(self::PERSONAL_DEFAULT);
    }

    public function value(): string
    {
        return $this->value;
    }
}
