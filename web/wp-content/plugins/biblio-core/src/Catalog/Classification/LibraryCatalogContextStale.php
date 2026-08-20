<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;

final class LibraryCatalogContextStale extends ConflictException
{
    public function __construct(
        private readonly LibraryCatalogContext $currentContext
    ) {
        parent::__construct(
            "The Library Catalog Context changed after it was loaded.",
            FailureReason::LibraryCatalogContextStale
        );
    }

    public function currentContext(): LibraryCatalogContext
    {
        return $this->currentContext;
    }
}
