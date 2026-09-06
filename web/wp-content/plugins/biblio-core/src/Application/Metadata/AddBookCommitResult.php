<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\Work;

final readonly class AddBookCommitResult
{
    public function __construct(
        private Work $work,
        private Edition $edition,
        private Item $item,
        private bool $existingEdition
    ) {
    }

    public function work(): Work { return $this->work; }
    public function edition(): Edition { return $this->edition; }
    public function item(): Item { return $this->item; }
    public function existingEdition(): bool { return $this->existingEdition; }
}
