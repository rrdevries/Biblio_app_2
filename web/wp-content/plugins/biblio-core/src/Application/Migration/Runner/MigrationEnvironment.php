<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

interface MigrationEnvironment
{
    public function assertHealthy(): void;

    public function provenance(): MigrationBuildProvenance;
}
