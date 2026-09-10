<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use LogicException;

final readonly class BibliographicDiscoveryQuery
{
    private function __construct(
        private BibliographicQueryType $type,
        private string $normalizedValue,
        private ?CanonicalIsbnIdentity $isbn
    ) {
    }

    public static function isbn(CanonicalIsbnIdentity $isbn): self
    {
        return new self(BibliographicQueryType::Isbn, $isbn->isbn13()->value(), $isbn);
    }

    public static function text(BibliographicTextQuery $query): self
    {
        return new self(BibliographicQueryType::Text, $query->value(), null);
    }

    public function type(): BibliographicQueryType { return $this->type; }
    public function normalizedValue(): string { return $this->normalizedValue; }

    public function requireIsbn(): CanonicalIsbnIdentity
    {
        return $this->isbn ?? throw new LogicException("Query is not an ISBN query.");
    }
}
