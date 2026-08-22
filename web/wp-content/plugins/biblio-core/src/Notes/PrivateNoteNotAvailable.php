<?php

declare(strict_types=1);

namespace Biblio\Core\Notes;

use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\FailureReason;

final class PrivateNoteNotAvailable extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct(
            "Private Note is not available to this user.",
            FailureReason::PrivateNoteNotAvailable
        );
    }
}
