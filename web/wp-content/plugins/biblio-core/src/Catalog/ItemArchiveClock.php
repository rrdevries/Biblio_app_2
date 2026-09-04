<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use DateTimeImmutable;

interface ItemArchiveClock
{
    public function now(): DateTimeImmutable;
}
