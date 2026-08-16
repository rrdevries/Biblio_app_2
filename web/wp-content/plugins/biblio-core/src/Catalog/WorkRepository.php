<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

interface WorkRepository
{
    public function add(Work $work): void;

    public function find(WorkId $workId): ?Work;
}
