<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Circulation;

use Biblio\Core\Application\Migration\Runner\MigrationParticipantFailure;
use RuntimeException;
use Throwable;

final class CirculationMigrationFailure extends RuntimeException implements MigrationParticipantFailure
{
    public function __construct(
        private readonly CirculationMigrationReason $reason,
        string $message,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function reasonCode(): string { return $this->reason->value; }
}
