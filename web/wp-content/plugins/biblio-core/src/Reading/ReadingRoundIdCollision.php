<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use RuntimeException;
use Throwable;

final class ReadingRoundIdCollision extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct("Reading Round ID already exists.", 0, $previous);
    }
}
