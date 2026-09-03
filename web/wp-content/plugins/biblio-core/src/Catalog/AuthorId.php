<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Identity\IdentifierConstraints;

final readonly class AuthorId
{
    public function __construct(private string $value)
    {
        IdentifierConstraints::assertValid($value, "Author ID");
    }

    public function value(): string { return $this->value; }
}
