<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Classification;

use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;

/**
 * The caller-selected classification for a context that may need to be
 * initialized as part of one authorized Item-add operation.
 */
final readonly class LibraryCatalogContextInitialization
{
    public function __construct(
        private LibraryCatalogSelection $selection
    ) {
    }

    public function selection(): LibraryCatalogSelection
    {
        return $this->selection;
    }
}
