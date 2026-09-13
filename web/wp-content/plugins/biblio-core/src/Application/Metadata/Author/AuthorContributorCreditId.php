<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Identity\IdentifierConstraints;

final readonly class AuthorContributorCreditId
{
    public function __construct(private string $value)
    {
        IdentifierConstraints::assertValid($value, "Author contributor credit ID");
    }

    public function value(): string
    {
        return $this->value;
    }
}
