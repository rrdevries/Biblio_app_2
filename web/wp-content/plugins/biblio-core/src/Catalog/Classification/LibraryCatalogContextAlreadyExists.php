<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;
use Throwable;

final class LibraryCatalogContextAlreadyExists extends ConflictException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            "A Library Catalog Context already exists for this Work.",
            FailureReason::LibraryCatalogContextAlreadyExists,
            $previous
        );
    }
}
