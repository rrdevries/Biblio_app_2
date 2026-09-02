<?php

declare(strict_types=1);

namespace Biblio\Core\NextReading;

use Biblio\Core\Exception\{ConflictException,FailureReason};

final class PreferredReadingSourceUnavailable extends ConflictException
{
    public function __construct()
    {
        parent::__construct(
            "Preferred reading source is unavailable.",
            FailureReason::PreferredReadingSourceUnavailable
        );
    }
}
