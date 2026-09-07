<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Catalog\Author;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\Work;

final readonly class AddBookExistingEdition
{
    /**
     * @param list<Author> $authors
     * @param list<AddBookExistingItem> $existingItems
     */
    public function __construct(
        private Work $work,
        private Edition $edition,
        private array $authors,
        private array $existingItems
    ) {
    }

    public function work(): Work { return $this->work; }
    public function edition(): Edition { return $this->edition; }
    /** @return list<Author> */
    public function authors(): array { return $this->authors; }
    /** @return list<AddBookExistingItem> */
    public function existingItems(): array { return $this->existingItems; }
}
