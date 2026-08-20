<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

interface WritableLibraryCatalogContextRepository extends
    LibraryCatalogContextRepository
{
    /** Must be called inside the owning application transaction. */
    public function add(LibraryCatalogContext $context): void;

    /**
     * Must be called inside the owning application transaction.
     * Returns false when the expected version is stale or the row is absent.
     */
    public function replaceIfVersionMatches(
        LibraryCatalogContext $replacement,
        LibraryCatalogContextVersion $expectedVersion
    ): bool;
}
