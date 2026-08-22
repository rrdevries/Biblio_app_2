<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Classification;

use Biblio\Core\Catalog\Classification\LibraryCatalogContext;

final readonly class LibraryCatalogContextInitializationResult
{
    private function __construct(
        private LibraryCatalogContext $context,
        private ?LibraryCatalogSelectionSnapshot $createdSelection
    ) {
    }

    public static function created(
        LibraryCatalogContext $context,
        LibraryCatalogSelectionSnapshot $selection
    ): self {
        return new self($context, $selection);
    }

    public static function reused(LibraryCatalogContext $context): self
    {
        return new self($context, null);
    }

    public function context(): LibraryCatalogContext
    {
        return $this->context;
    }

    public function createdSelection(): ?LibraryCatalogSelectionSnapshot
    {
        return $this->createdSelection;
    }
}
