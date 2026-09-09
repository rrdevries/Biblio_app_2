<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use DateTimeImmutable;

interface PersonalReadingTruthClock
{
    public function now(): DateTimeImmutable;
}
