<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Collections\CollectionClock;
use DateTimeImmutable;
use DateTimeZone;

final readonly class SystemCollectionClock implements CollectionClock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('UTC')); }
}
