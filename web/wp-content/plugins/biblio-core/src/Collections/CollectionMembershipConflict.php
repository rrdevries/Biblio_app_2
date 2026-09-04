<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;

final class CollectionMembershipConflict extends ConflictException
{
    public function __construct()
    {
        parent::__construct("Collection membership change is invalid.", FailureReason::CollectionMembershipConflict);
    }
}
