<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

interface SourceMappingExclusionPlan extends TypedMigrationPlan
{
    /** @return list<array{source_type:string,source_id:string}> */
    public function forbiddenSourceIdentities(): array;
}
