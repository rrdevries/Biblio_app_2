<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\FailureReason;

final class CatalogItemNotAvailable extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct(
            "Catalog Item is not available in this Library context.",
            FailureReason::CatalogItemNotAvailable
        );
    }
}
