<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Exception\ValidationException;

final readonly class AuthorContributorCreditVersion
{
    public function __construct(private int $value)
    {
        if ($value < 1) {
            throw new ValidationException(
                "Author contributor credit version must be positive."
            );
        }
    }

    public static function initial(): self
    {
        return new self(1);
    }

    public function value(): int
    {
        return $this->value;
    }
}
