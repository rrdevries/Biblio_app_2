<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\IdentifierConstraints;

final readonly class InventoryNumber
{
    private string $value;

    public function __construct(string $value)
    {
        $normalized = trim($value);

        if ($normalized === "") {
            throw new ValidationException(
                "Inventory number must not be empty."
            );
        }

        $length = preg_match_all('/./us', $normalized);

        if ($length === false) {
            throw new ValidationException(
                "Inventory number must be valid UTF-8."
            );
        }

        if ($length > IdentifierConstraints::MAX_LENGTH) {
            throw new ValidationException(
                "Inventory number must not exceed "
                . IdentifierConstraints::MAX_LENGTH . " characters."
            );
        }

        $this->value = $normalized;
    }

    public function value(): string { return $this->value; }
}
