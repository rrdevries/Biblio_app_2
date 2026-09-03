<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class Isbn13 implements Isbn
{
    private string $value;

    public function __construct(string $value)
    {
        $normalized = (string) preg_replace('/[-\s]+/u', '', $value);

        if (preg_match('/^[0-9]{13}$/D', $normalized) !== 1) {
            throw new ValidationException("ISBN-13 has an invalid shape.");
        }

        $sum = 0;
        for ($index = 0; $index < 12; ++$index) {
            $weight = $index % 2 === 0 ? 1 : 3;
            $sum += $weight * (int) $normalized[$index];
        }
        $checkDigit = (10 - ($sum % 10)) % 10;

        if ($checkDigit !== (int) $normalized[12]) {
            throw new ValidationException("ISBN-13 checksum is invalid.");
        }

        $this->value = $normalized;
    }

    public function value(): string { return $this->value; }
    public function type(): IsbnType { return IsbnType::Isbn13; }
}
