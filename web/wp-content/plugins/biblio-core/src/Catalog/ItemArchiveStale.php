<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;

final class ItemArchiveStale extends ConflictException
{
    public function __construct(private readonly Item $current)
    {
        parent::__construct(
            "Item changed since it was loaded.",
            FailureReason::ItemArchiveStale
        );
    }

    public function current(): Item { return $this->current; }
}
