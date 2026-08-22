<?php

declare(strict_types=1);

namespace Biblio\Core\Notes;

use DateTimeImmutable;

interface PrivateNoteClock
{
    public function now(): DateTimeImmutable;
}
