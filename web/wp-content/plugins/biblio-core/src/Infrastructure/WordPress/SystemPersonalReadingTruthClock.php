<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Reading\PersonalReadingTruthClock;
use DateTimeImmutable;
use DateTimeZone;

final readonly class SystemPersonalReadingTruthClock implements PersonalReadingTruthClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("now", new DateTimeZone("UTC"));
    }
}
