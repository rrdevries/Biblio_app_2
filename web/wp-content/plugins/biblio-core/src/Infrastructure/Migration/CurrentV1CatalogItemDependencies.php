<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\ItemLocalDetailsState;
use Biblio\Core\Exception\ValidationException;

final readonly class CurrentV1CatalogItemDependencies
{
    public function __construct(
        private ?LibraryCatalogSelection $classification,
        private bool $itemLocalReviewed,
        private ?ItemLocalDetailsState $localDetails = null
    ) {
        if (!$this->itemLocalReviewed && $this->localDetails !== null) {
            throw new ValidationException(
                "Unreviewed Item-local dependency cannot contain mapped details."
            );
        }
    }

    public static function unresolved(): self
    {
        return new self(null, false);
    }

    public static function reviewed(
        LibraryCatalogSelection $classification,
        ?ItemLocalDetailsState $localDetails
    ): self {
        return new self($classification, true, $localDetails);
    }

    public function classification(): ?LibraryCatalogSelection
    {
        return $this->classification;
    }

    public function itemLocalReviewed(): bool { return $this->itemLocalReviewed; }
    public function localDetails(): ?ItemLocalDetailsState { return $this->localDetails; }
}
