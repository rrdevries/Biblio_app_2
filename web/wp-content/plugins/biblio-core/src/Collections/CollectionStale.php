<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;

final class CollectionStale extends ConflictException
{
    public function __construct(private readonly LibraryCollection $current)
    {
        parent::__construct("Collection changed since it was loaded.", FailureReason::CollectionStale);
    }

    public function current(): LibraryCollection { return $this->current; }
}
