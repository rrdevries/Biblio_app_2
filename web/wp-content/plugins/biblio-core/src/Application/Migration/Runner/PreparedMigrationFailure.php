<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

final readonly class PreparedMigrationFailure
{
    public function __construct(
        private MigrationSourceRecord $record,
        private string $reasonCode
    ) {
    }

    public function record(): MigrationSourceRecord { return $this->record; }
    public function reasonCode(): string { return $this->reasonCode; }
}
