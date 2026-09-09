<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Migration;

use Biblio\Core\Application\Migration\MigrationRunIdGenerator;

final readonly class OpaqueMigrationRunIdGenerator implements MigrationRunIdGenerator
{
    public function next(): string
    {
        return "migration-run-" . bin2hex(random_bytes(16));
    }
}
