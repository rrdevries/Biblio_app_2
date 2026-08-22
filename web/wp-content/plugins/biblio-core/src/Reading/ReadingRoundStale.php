<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;

final class ReadingRoundStale extends ConflictException
{
    public function __construct(private readonly ReadingRound $current)
    {
        parent::__construct(
            "Reading Round changed since it was loaded.",
            FailureReason::ReadingRoundStale
        );
    }

    public function current(): ReadingRound
    {
        return $this->current;
    }
}
