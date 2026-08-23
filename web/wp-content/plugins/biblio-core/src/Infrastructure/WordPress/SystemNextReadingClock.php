<?php
declare(strict_types=1);
namespace Biblio\Core\Infrastructure\WordPress;
use Biblio\Core\NextReading\NextReadingClock;
use DateTimeImmutable;
use DateTimeZone;
final readonly class SystemNextReadingClock implements NextReadingClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("now", new DateTimeZone("UTC"));
    }
}
