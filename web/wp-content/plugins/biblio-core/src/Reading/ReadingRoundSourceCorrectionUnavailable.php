<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\FailureReason;

final class ReadingRoundSourceCorrectionUnavailable extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct(
            "Reading Round source correction is not available.",
            FailureReason::ReadingRoundSourceCorrectionUnavailable
        );
    }
}
