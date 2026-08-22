<?php

declare(strict_types=1);

namespace Biblio\Core\Notes;

use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\FailureReason;

final class PrivateNoteReadingRoundUnavailable extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct(
            "Reading Round is not available as Private Note context.",
            FailureReason::PrivateNoteReadingRoundUnavailable
        );
    }
}
