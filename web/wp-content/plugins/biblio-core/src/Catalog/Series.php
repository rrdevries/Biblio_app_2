<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class Series
{
    public const MAX_NAME_LENGTH = 512;

    public function __construct(
        private SeriesId $id,
        private string $displayName
    ) {
        $length = preg_match_all('/./us', $displayName);
        if ($length === false) {
            throw new ValidationException("Series name must be valid UTF-8.");
        }
        if (trim($displayName) === "") {
            throw new ValidationException("Series name must not be empty.");
        }
        if ($length > self::MAX_NAME_LENGTH) {
            throw new ValidationException(
                "Series name must not exceed " . self::MAX_NAME_LENGTH . " characters."
            );
        }
    }

    public function id(): SeriesId { return $this->id; }
    public function displayName(): string { return $this->displayName; }
}
