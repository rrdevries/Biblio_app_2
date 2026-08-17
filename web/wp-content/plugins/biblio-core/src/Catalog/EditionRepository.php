<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

interface EditionRepository
{
    public function find(EditionId $editionId): ?Edition;
}
