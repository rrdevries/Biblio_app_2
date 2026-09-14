<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitialization;
use Biblio\Core\Catalog\InventoryNumber;
use Biblio\Core\Catalog\LocationId;
use InvalidArgumentException;

final readonly class AddBookCommitRequest
{
    /** @var list<ManualAuthorInput> */
    private array $authors;

    /** @param array<array-key, mixed> $authors */
    public function __construct(
        private ?string $identifier,
        private AddBookCommitSelection $selection,
        private AddBookObservedMetadata $observations,
        private LibraryCatalogContextInitialization $classification,
        private ?InventoryNumber $inventoryNumber = null,
        private ?LocationId $locationId = null,
        array $authors = []
    ) {
        if (
            $identifier !== null
            && ($identifier === "" || strlen($identifier) > 64)
        ) {
            throw new InvalidArgumentException("Invalid Add Book identifier.");
        }
        if (
            in_array($selection->type(), [
                AddBookSelectionType::Candidate,
                AddBookSelectionType::ExistingEdition,
            ], true)
            && $identifier === null
        ) {
            throw new InvalidArgumentException(
                "The Add Book selection requires an ISBN identifier."
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
        if (!array_is_list($authors) || count($authors) > 32) {
            throw new InvalidArgumentException(
                "Add Book Authors must be a list of at most 32 entries."
            );
        }
        foreach ($authors as $author) {
            if (!$author instanceof ManualAuthorInput) {
                throw new InvalidArgumentException(
                    "Add Book Authors contain an invalid entry."
                );
            }
        }
        /** @var list<ManualAuthorInput> $authors */
        $this->authors = $authors;
    }

    public function identifier(): ?string { return $this->identifier; }
    public function selection(): AddBookCommitSelection { return $this->selection; }
    public function observations(): AddBookObservedMetadata { return $this->observations; }
    public function classification(): LibraryCatalogContextInitialization { return $this->classification; }
    public function inventoryNumber(): ?InventoryNumber { return $this->inventoryNumber; }
    public function locationId(): ?LocationId { return $this->locationId; }
    /** @return list<ManualAuthorInput> */
    public function authors(): array { return $this->authors; }
}
