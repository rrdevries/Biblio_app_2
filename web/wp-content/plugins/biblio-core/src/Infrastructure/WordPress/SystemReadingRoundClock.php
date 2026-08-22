<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Reading\ReadingRoundClock;
use DateTimeImmutable;
use DateTimeZone;

final readonly class SystemReadingRoundClock implements ReadingRoundClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("now", new DateTimeZone("UTC"));
    }
}
