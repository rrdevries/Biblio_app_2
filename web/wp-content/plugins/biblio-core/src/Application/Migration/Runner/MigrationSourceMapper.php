<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

interface MigrationSourceMapper
{
    public function adapterId(): string;

    public function map(
        MigrationSourceInspection $inspection,
        MigrationPlanningTarget $target
    ): MigrationSourceMappingResult;
}
