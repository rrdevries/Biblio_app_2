<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Migration;

use Biblio\Core\Application\Migration\MigrationClock;
use DateTimeImmutable;
use DateTimeZone;

final readonly class SystemMigrationClock implements MigrationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("now", new DateTimeZone("UTC"));
    }
}
