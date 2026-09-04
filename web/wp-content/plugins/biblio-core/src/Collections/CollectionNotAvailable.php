<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\FailureReason;

final class CollectionNotAvailable extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct("Collection is not available in this Library context.", FailureReason::CollectionNotAvailable);
    }
}
