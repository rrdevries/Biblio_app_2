<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Preservation;

use Biblio\Core\Application\Migration\Runner\MigrationParticipantFailure;
use RuntimeException;

final class PreservedSourceEvidenceMigrationFailure extends RuntimeException implements MigrationParticipantFailure
{
    public function __construct(private string $migrationReason, string $message)
    {
        parent::__construct($message);
    }

    public function reasonCode(): string { return $this->migrationReason; }
}
