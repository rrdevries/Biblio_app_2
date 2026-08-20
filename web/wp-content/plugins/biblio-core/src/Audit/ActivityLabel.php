<?php

declare(strict_types=1);

namespace Biblio\Core\Audit;

use Biblio\Core\Exception\ValidationException;

final readonly class ActivityLabel
{
    public function __construct(private string $value)
    {
        if (
            preg_match('//u', $this->value) !== 1
            || preg_match('/^[\p{Z}\s]*$/u', $this->value) === 1
        ) {
            throw new ValidationException(
                "Activity snapshot label must be non-empty valid UTF-8."
            );
        }
    }

    public function value(): string
    {
        return $this->value;
    }
}
