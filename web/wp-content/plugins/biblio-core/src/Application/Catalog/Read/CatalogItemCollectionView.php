<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Collections\CollectionId;

final readonly class CatalogItemCollectionView
{
    public function __construct(
        private CollectionId $collectionId,
        private string $displayName
    ) {
    }

    public function collectionId(): CollectionId
    {
        return $this->collectionId;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }
}
