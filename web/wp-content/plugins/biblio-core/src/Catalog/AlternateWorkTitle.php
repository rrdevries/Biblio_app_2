<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class AlternateWorkTitle
{
    public const MAX_LENGTH = Work::MAX_TITLE_LENGTH;

    private string $normalizedKey;

    public function __construct(
        private WorkId $workId,
        private string $value
    ) {
        $length = preg_match_all('/./us', $value);

        if ($length === false) {
            throw new ValidationException(
                "Alternate Work title must be valid UTF-8."
            );
        }

        if (trim($value) === "") {
            throw new ValidationException(
                "Alternate Work title must not be empty."
            );
        }

        if ($length > self::MAX_LENGTH) {
            throw new ValidationException(
                "Alternate Work title must not exceed "
                . self::MAX_LENGTH . " characters."
            );
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        if (!is_string($normalized) || $normalized === "") {
            throw new ValidationException(
                "Alternate Work title could not be normalized."
            );
        }

        $this->normalizedKey = mb_strtolower($normalized, "UTF-8");
    }

    public function workId(): WorkId { return $this->workId; }
    public function value(): string { return $this->value; }
    public function normalizedKey(): string { return $this->normalizedKey; }
}
