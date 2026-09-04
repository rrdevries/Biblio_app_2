<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;

final readonly class LibraryLocation
{
    public const MAX_NAME_LENGTH = 512;

    public function __construct(
        private LocationId $id,
        private LibraryId $libraryId,
        private string $displayName
    ) {
        $length = preg_match_all('/./us', $displayName);
        if ($length === false) {
            throw new ValidationException("Location name must be valid UTF-8.");
        }
        if (trim($displayName) === "") {
            throw new ValidationException("Location name must not be empty.");
        }
        if ($length > self::MAX_NAME_LENGTH) {
            throw new ValidationException(
                "Location name must not exceed " . self::MAX_NAME_LENGTH . " characters."
            );
        }
    }

    public function id(): LocationId { return $this->id; }
    public function libraryId(): LibraryId { return $this->libraryId; }
    public function displayName(): string { return $this->displayName; }
}
