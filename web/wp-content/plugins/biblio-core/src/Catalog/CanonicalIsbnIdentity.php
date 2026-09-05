<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class CanonicalIsbnIdentity
{
    public function __construct(
        private Isbn13 $isbn13,
        private ?Isbn10 $isbn10
    ) {
        if (
            $isbn10 !== null
            && IsbnRules::isbn10To13($isbn10->value()) !== $isbn13->value()
        ) {
            throw new ValidationException(
                "ISBN-10 and ISBN-13 do not identify the same Edition."
            );
        }
    }

    public static function fromIsbn(Isbn $isbn): self
    {
        if ($isbn instanceof Isbn10) {
            return new self(
                new Isbn13(IsbnRules::isbn10To13($isbn->value())),
                $isbn
            );
        }

        if (!$isbn instanceof Isbn13) {
            throw new ValidationException("Unsupported ISBN value type.");
        }

        $isbn10 = IsbnRules::isbn13To10($isbn->value());

        return new self(
            $isbn,
            $isbn10 === null ? null : new Isbn10($isbn10)
        );
    }

    public static function fromMetadata(EditionIsbnMetadata $metadata): ?self
    {
        if ($metadata->isbn13() !== null) {
            return new self($metadata->isbn13(), $metadata->isbn10());
        }

        return $metadata->isbn10() === null
            ? null
            : self::fromIsbn($metadata->isbn10());
    }

    public function isbn13(): Isbn13 { return $this->isbn13; }
    public function isbn10(): ?Isbn10 { return $this->isbn10; }

    public function metadata(): EditionIsbnMetadata
    {
        return EditionIsbnMetadata::identified($this->isbn10, $this->isbn13);
    }
}
