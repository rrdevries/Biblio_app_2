<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use InvalidArgumentException;

final readonly class MetadataLookupId
{
    public function __construct(private string $value)
    {
        if (
            preg_match('/^lookup-[0-9a-f]{32}$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException("Invalid metadata lookup ID.");
        }
    }

    public function value(): string { return $this->value; }
}
