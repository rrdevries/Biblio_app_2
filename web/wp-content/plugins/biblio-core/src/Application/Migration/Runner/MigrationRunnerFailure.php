<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use RuntimeException;
use Throwable;

final class MigrationRunnerFailure extends RuntimeException
{
    public function __construct(
        private readonly MigrationRunnerReason $reason,
        string $message,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function reason(): MigrationRunnerReason
    {
        return $this->reason;
    }
}
