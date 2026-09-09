<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;

final class PersonalReadingTruthStale extends ConflictException
{
    public function __construct()
    {
        parent::__construct(
            "Personal Reading Truth changed during the operation.",
            FailureReason::PersonalReadingTruthStale
        );
    }
}
