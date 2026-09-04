<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

use DateTimeImmutable;

interface CollectionClock
{
    public function now(): DateTimeImmutable;
}
