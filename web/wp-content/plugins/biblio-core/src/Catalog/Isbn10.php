<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class Isbn10 implements Isbn
{
    private string $value;

    public function __construct(string $value)
    {
        $normalized = strtoupper((string) preg_replace('/[-\s]+/u', '', $value));

        if (preg_match('/^[0-9]{9}[0-9X]$/D', $normalized) !== 1) {
            throw new ValidationException("ISBN-10 has an invalid shape.");
        }

        $sum = 0;
        for ($index = 0; $index < 10; ++$index) {
            $digit = $normalized[$index] === "X"
                ? 10
                : (int) $normalized[$index];
            $sum += (10 - $index) * $digit;
        }

        if ($sum % 11 !== 0) {
            throw new ValidationException("ISBN-10 checksum is invalid.");
        }

        $this->value = $normalized;
    }

    public function value(): string { return $this->value; }
    public function type(): IsbnType { return IsbnType::Isbn10; }
}
