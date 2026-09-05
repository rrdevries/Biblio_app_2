<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

final readonly class IsbnParseResult
{
    private function __construct(
        private ?CanonicalIsbnIdentity $identity,
        private ?IsbnInputError $error
    ) {
    }

    public static function valid(CanonicalIsbnIdentity $identity): self
    {
        return new self($identity, null);
    }

    public static function invalid(IsbnInputError $error): self
    {
        return new self(null, $error);
    }

    public function isValid(): bool { return $this->identity !== null; }
    public function identity(): ?CanonicalIsbnIdentity { return $this->identity; }
    public function error(): ?IsbnInputError { return $this->error; }
}
