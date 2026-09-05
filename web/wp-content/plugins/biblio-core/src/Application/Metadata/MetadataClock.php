<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use DateTimeImmutable;

interface MetadataClock
{
    public function now(): DateTimeImmutable;
}
