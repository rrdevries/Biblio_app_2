<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use DateTimeImmutable;

interface ReadingRoundClock
{
    public function now(): DateTimeImmutable;
}
