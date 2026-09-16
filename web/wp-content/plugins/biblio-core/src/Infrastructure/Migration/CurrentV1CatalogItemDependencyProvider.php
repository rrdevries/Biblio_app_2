<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Runner\MigrationPlanningTarget;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;

interface CurrentV1CatalogItemDependencyProvider
{
    public function forCopy(
        MigrationSourceRecord $copy,
        MigrationSourceRecord $book,
        MigrationPlanningTarget $target
    ): CurrentV1CatalogItemDependencies;
}
