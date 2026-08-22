<?php

declare(strict_types=1);

namespace Biblio\Core\Notes;

use RuntimeException;
use Throwable;

final class PrivateNoteIdCollision extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct("Private Note ID already exists.", 0, $previous);
    }
}
