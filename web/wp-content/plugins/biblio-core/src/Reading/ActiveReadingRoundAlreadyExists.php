<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;
use Throwable;

final class ActiveReadingRoundAlreadyExists extends ConflictException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            "An active Reading Round already exists for this source.",
            FailureReason::ReadingRoundAlreadyActiveForSource,
            $previous
        );
    }
}
