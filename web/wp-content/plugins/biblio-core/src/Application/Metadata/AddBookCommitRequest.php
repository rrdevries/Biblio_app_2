<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitialization;
use Biblio\Core\Catalog\InventoryNumber;
use Biblio\Core\Catalog\LocationId;
use InvalidArgumentException;

final readonly class AddBookCommitRequest
{
    public function __construct(
        private ?string $identifier,
        private AddBookCommitSelection $selection,
        private AddBookObservedMetadata $observations,
        private LibraryCatalogContextInitialization $classification,
        private ?InventoryNumber $inventoryNumber = null,
        private ?LocationId $locationId = null
    ) {
        if (
            $identifier !== null
            && ($identifier === "" || strlen($identifier) > 64)
        ) {
            throw new InvalidArgumentException("Invalid Add Book identifier.");
        }
        if (
            $selection->type() === AddBookSelectionType::Candidate
            && $identifier === null
        ) {
            throw new InvalidArgumentException(
                "A reviewed candidate requires an ISBN identifier."
            );
        }
        if (
            $identifier === null
            && $observations->value(UserObservedMetadataField::Isbn) !== null
        ) {
            throw new InvalidArgumentException(
                "An observed ISBN must also be supplied as the identifier."
            );
        }
    }

    public function identifier(): ?string { return $this->identifier; }
    public function selection(): AddBookCommitSelection { return $this->selection; }
    public function observations(): AddBookObservedMetadata { return $this->observations; }
    public function classification(): LibraryCatalogContextInitialization { return $this->classification; }
    public function inventoryNumber(): ?InventoryNumber { return $this->inventoryNumber; }
    public function locationId(): ?LocationId { return $this->locationId; }
}
