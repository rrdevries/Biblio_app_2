<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Catalog\ItemArchiveClock;
use DateTimeImmutable;
use DateTimeZone;

final readonly class SystemItemArchiveClock implements ItemArchiveClock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable("now", new DateTimeZone("UTC")); }
}
