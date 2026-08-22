<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;
use Throwable;

final class ReadingRoundIdCollisionExhausted extends ConflictException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            "Could not issue a unique Reading Round ID after three retries.",
            FailureReason::ReadingRoundIdCollisionExhausted,
            $previous
        );
    }
}
