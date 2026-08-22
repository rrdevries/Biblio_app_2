<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;

final class ReadingRoundDeletionNotAllowed extends ConflictException
{
    public function __construct()
    {
        parent::__construct(
            "This Reading Round cannot be hard deleted.",
            FailureReason::ReadingRoundDeletionNotAllowed
        );
    }
}
