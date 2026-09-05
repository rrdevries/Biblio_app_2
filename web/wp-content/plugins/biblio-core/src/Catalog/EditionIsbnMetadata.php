<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class EditionIsbnMetadata
{
    private function __construct(
        private ?Isbn10 $isbn10,
        private ?Isbn13 $isbn13,
        private bool $explicitlyWithoutIsbn
    ) {
        if (
            $explicitlyWithoutIsbn
            && ($isbn10 !== null || $isbn13 !== null)
        ) {
            throw new ValidationException(
                "An Edition without ISBN cannot contain an ISBN."
            );
        }
    }

    public static function unknown(): self
    {
        return new self(null, null, false);
    }

    public static function withoutIsbn(): self
    {
        return new self(null, null, true);
    }

    public static function identified(
        ?Isbn10 $isbn10,
        ?Isbn13 $isbn13
    ): self {
        if ($isbn10 === null && $isbn13 === null) {
            throw new ValidationException(
                "Identified Edition ISBN metadata requires an ISBN."
            );
        }

        if (
            $isbn10 !== null
            && $isbn13 !== null
            && IsbnRules::isbn10To13($isbn10->value()) !== $isbn13->value()
        ) {
            throw new ValidationException(
                "ISBN-10 and ISBN-13 do not identify the same Edition."
            );
        }

        return new self($isbn10, $isbn13, false);
    }

    public function isbn10(): ?Isbn10 { return $this->isbn10; }
    public function isbn13(): ?Isbn13 { return $this->isbn13; }
    public function isExplicitlyWithoutIsbn(): bool
    {
        return $this->explicitlyWithoutIsbn;
    }
}
