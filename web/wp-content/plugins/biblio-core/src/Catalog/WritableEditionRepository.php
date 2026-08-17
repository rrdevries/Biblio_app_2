<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

interface WritableEditionRepository extends EditionRepository
{
    public function add(Edition $edition): void;
}
