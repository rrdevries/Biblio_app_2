<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\FailureReason;

final class ReadingRoundNotAvailable extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct(
            "Reading Round is not available to this user.",
            FailureReason::ReadingRoundNotAvailable
        );
    }
}
