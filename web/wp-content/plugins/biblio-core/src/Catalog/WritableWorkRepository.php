<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

interface WritableWorkRepository extends WorkRepository
{
    public function add(Work $work): void;
}
