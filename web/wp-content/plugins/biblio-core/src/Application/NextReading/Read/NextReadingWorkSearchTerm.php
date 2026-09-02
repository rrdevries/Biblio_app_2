<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading\Read;

use Biblio\Core\Exception\ValidationException;

final readonly class NextReadingWorkSearchTerm
{
    public const MAXIMUM_LENGTH = 100;

    private string $value;

    public function __construct(string $value)
    {
        if (!mb_check_encoding($value, "UTF-8")) {
            throw new ValidationException("Work search must be valid UTF-8.");
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        if ($normalized === null || $normalized === "") {
            throw new ValidationException("Work search must not be empty.");
        }

        if (mb_strlen($normalized, "UTF-8") > self::MAXIMUM_LENGTH) {
            throw new ValidationException(
                "Work search must not exceed " . self::MAXIMUM_LENGTH
                . " characters."
            );
        }

        $this->value = $normalized;
    }

    public function value(): string { return $this->value; }
}
