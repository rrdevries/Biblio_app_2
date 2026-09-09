<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

interface MigrationRunIdGenerator
{
    public function next(): string;
}
