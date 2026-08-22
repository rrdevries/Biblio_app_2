<?php

declare(strict_types=1);

namespace Biblio\Core\Notes;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;

final class PrivateNoteStale extends ConflictException
{
    public function __construct(private readonly PrivateNote $current)
    {
        parent::__construct(
            "Private Note changed since it was loaded.",
            FailureReason::PrivateNoteStale
        );
    }

    public function current(): PrivateNote { return $this->current; }
}
