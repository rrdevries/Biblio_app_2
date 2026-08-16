<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

interface EditionRepository
{
    public function add(Edition $edition): void;

    public function find(EditionId $editionId): ?Edition;
}
