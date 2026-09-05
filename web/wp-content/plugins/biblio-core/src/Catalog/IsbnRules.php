<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

final class IsbnRules
{
    public static function normalized(string $input): string
    {
        return strtoupper((string) preg_replace('/[-\s]+/u', '', $input));
    }

    public static function assertIsbn10(string $value): void
    {
        self::assertCommonShape($value, 10, '/^[0-9]{9}[0-9X]$/D');
        $sum = 0;

        for ($index = 0; $index < 10; ++$index) {
            $digit = $value[$index] === "X" ? 10 : (int) $value[$index];
            $sum += (10 - $index) * $digit;
        }

        if ($sum % 11 !== 0) {
            throw new InvalidIsbnInput(
                IsbnInputError::InvalidChecksum,
                "ISBN-10 checksum is invalid."
            );
        }
    }

    public static function assertIsbn13(string $value): void
    {
        self::assertCommonShape($value, 13, '/^[0-9]{13}$/D');

        if (!str_starts_with($value, "978") && !str_starts_with($value, "979")) {
            throw new InvalidIsbnInput(
                IsbnInputError::UnsupportedPrefix,
                "ISBN-13 prefix is not supported."
            );
        }

        if (self::isbn13CheckDigit(substr($value, 0, 12)) !== (int) $value[12]) {
            throw new InvalidIsbnInput(
                IsbnInputError::InvalidChecksum,
                "ISBN-13 checksum is invalid."
            );
        }
    }

    public static function isbn10To13(string $isbn10): string
    {
        self::assertIsbn10($isbn10);
        $body = "978" . substr($isbn10, 0, 9);

        return $body . self::isbn13CheckDigit($body);
    }

    public static function isbn13To10(string $isbn13): ?string
    {
        self::assertIsbn13($isbn13);

        if (!str_starts_with($isbn13, "978")) {
            return null;
        }

        $body = substr($isbn13, 3, 9);
        $sum = 0;
        for ($index = 0; $index < 9; ++$index) {
            $sum += (10 - $index) * (int) $body[$index];
        }
        $check = (11 - ($sum % 11)) % 11;

        return $body . ($check === 10 ? "X" : (string) $check);
    }

    private static function assertCommonShape(
        string $value,
        int $length,
        string $pattern
    ): void {
        if ($value === "") {
            throw new InvalidIsbnInput(
                IsbnInputError::Empty,
                "ISBN input is empty."
            );
        }

        if (strlen($value) !== $length) {
            throw new InvalidIsbnInput(
                IsbnInputError::InvalidLength,
                "ISBN has an invalid length."
            );
        }

        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidIsbnInput(
                IsbnInputError::InvalidCharacters,
                "ISBN contains invalid characters."
            );
        }
    }

    private static function isbn13CheckDigit(string $body): int
    {
        $sum = 0;
        for ($index = 0; $index < 12; ++$index) {
            $sum += ($index % 2 === 0 ? 1 : 3) * (int) $body[$index];
        }

        return (10 - ($sum % 10)) % 10;
    }
}
