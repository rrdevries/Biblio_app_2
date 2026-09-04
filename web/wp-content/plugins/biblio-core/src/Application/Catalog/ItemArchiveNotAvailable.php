<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog;

use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\FailureReason;

final class ItemArchiveNotAvailable extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct(
            "Item archive lifecycle is not available in this Library context.",
            FailureReason::CatalogItemNotAvailable
        );
    }
}
