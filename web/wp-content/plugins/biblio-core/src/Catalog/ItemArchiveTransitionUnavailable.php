<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;

final class ItemArchiveTransitionUnavailable extends ConflictException
{
    public function __construct()
    {
        parent::__construct(
            "Item archive transition is not available.",
            FailureReason::ItemArchiveTransitionUnavailable
        );
    }
}
