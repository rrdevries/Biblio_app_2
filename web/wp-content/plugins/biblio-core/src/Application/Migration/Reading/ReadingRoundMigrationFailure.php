<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reading;

use Biblio\Core\Application\Migration\Runner\MigrationParticipantFailure;
use RuntimeException;
use Throwable;

final class ReadingRoundMigrationFailure extends RuntimeException implements
    MigrationParticipantFailure
{
    public function __construct(
        private readonly ReadingRoundMigrationReason $reason,
        string $message,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function reason(): ReadingRoundMigrationReason { return $this->reason; }
    public function reasonCode(): string { return $this->reason->value; }
}
