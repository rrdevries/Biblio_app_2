<?php

declare(strict_types=1);

namespace Biblio\Core\Notes;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;
use Throwable;

final class PrivateNoteIdCollisionExhausted extends ConflictException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            "Could not issue a unique Private Note ID after three retries.",
            FailureReason::PrivateNoteIdCollisionExhausted,
            $previous
        );
    }
}
