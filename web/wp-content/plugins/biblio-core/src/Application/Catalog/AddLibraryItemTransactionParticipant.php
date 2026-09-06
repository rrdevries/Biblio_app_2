<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog;

use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\Work;

interface AddLibraryItemTransactionParticipant
{
    public function apply(
        Work $work,
        Edition $edition,
        Item $item,
        bool $existingEdition
    ): void;
}
