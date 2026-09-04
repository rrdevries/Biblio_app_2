<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;

final class CollectionNameConflict extends ConflictException
{
    public function __construct()
    {
        parent::__construct("An active Collection with this normalized name already exists.", FailureReason::CollectionNameConflict);
    }
}
