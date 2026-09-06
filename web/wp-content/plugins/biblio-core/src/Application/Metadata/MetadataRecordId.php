<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use InvalidArgumentException;

final readonly class MetadataRecordId
{
    public function __construct(private string $value)
    {
        if (
            $value === ""
            || trim($value) !== $value
            || strlen($value) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException("Invalid metadata record ID.");
        }
    }

    public function value(): string { return $this->value; }
}
