<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

final readonly class Isbn10 implements Isbn
{
    private string $value;

    public function __construct(string $value)
    {
        $normalized = IsbnRules::normalized($value);
        IsbnRules::assertIsbn10($normalized);

        $this->value = $normalized;
    }

    public function value(): string { return $this->value; }
    public function type(): IsbnType { return IsbnType::Isbn10; }
}
