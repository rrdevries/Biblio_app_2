<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class ContributorPosition
{
    public function __construct(private int $value)
    {
        if ($value < 1) {
            throw new ValidationException("Contributor position must be positive.");
        }
    }

    public function value(): int { return $this->value; }
}
