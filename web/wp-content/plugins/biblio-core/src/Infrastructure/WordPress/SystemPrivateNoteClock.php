<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Notes\PrivateNoteClock;
use DateTimeImmutable;
use DateTimeZone;

final readonly class SystemPrivateNoteClock implements PrivateNoteClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("now", new DateTimeZone("UTC"));
    }
}
