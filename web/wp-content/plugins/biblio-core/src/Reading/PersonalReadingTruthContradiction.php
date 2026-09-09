<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;

final class PersonalReadingTruthContradiction extends ConflictException
{
    public function __construct()
    {
        parent::__construct(
            "Explicit not-read truth contradicts an existing completed Reading Round.",
            FailureReason::PersonalReadingTruthContradiction
        );
    }
}
