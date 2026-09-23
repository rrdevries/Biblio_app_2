<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use RuntimeException;
use Throwable;

/** Closed operational failures never contain source bodies, SQL or credentials. */
final class RehearsalFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct("Guarded rehearsal refused: " . $reason . ".", 0, $previous);
    }
}
