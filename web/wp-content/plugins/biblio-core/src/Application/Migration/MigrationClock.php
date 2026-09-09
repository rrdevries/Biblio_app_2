<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

use DateTimeImmutable;

interface MigrationClock
{
    public function now(): DateTimeImmutable;
}
