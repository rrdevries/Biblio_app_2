<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Catalog;

use Biblio\Core\Application\Migration\Runner\TypedMigrationPlan;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\InventoryNumber;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemLocalDetailsState;
use Biblio\Core\Catalog\LocationId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;

final readonly class CatalogItemPlan implements TypedMigrationPlan
{
    public function __construct(
        private string $editionSourceId,
        private LibraryId $targetLibraryId,
        private LibraryCatalogSelection $classification,
        private ?InventoryNumber $inventoryNumber = null,
        private ?LocationId $locationId = null,
        private ?ItemLocalDetailsState $localDetails = null,
        private ?ItemId $approvedExistingItemId = null,
        private ?CatalogItemPreservationPlan $preservation = null
    ) {
        if (trim($this->editionSourceId) === "" || mb_strlen($this->editionSourceId) > 191) {
            throw new ValidationException("Edition source reference is invalid.");
        }
    }

    public function editionSourceId(): string { return $this->editionSourceId; }
    public function targetLibraryId(): LibraryId { return $this->targetLibraryId; }
    public function classification(): LibraryCatalogSelection { return $this->classification; }
    public function inventoryNumber(): ?InventoryNumber { return $this->inventoryNumber; }
    public function locationId(): ?LocationId { return $this->locationId; }
    public function localDetails(): ?ItemLocalDetailsState { return $this->localDetails; }
    public function approvedExistingItemId(): ?ItemId { return $this->approvedExistingItemId; }
    public function preservation(): ?CatalogItemPreservationPlan
    {
        return $this->preservation;
    }

    public function canonicalPayload(): array
    {
        $selection = $this->classification;
        $details = $this->localDetails;
        $date = $details?->inLibrarySince();
        $amount = $details?->paidAmount();

        return [
            "target_kind" => "catalog_item",
            "edition_source_id" => $this->editionSourceId,
            "target_library_id" => $this->targetLibraryId->value(),
            "inventory_number" => $this->inventoryNumber?->value(),
            "location_id" => $this->locationId?->value(),
            "classification" => [
                "book_type_id" => $selection->bookTypeId()->value(),
                "genre_ids" => array_map(
                    static fn ($id): string => $id->value(),
                    $selection->genreIds()
                ),
                "subject_ids" => array_map(
                    static fn ($id): string => $id->value(),
                    $selection->subjectIds()
                ),
            ],
            "local_details" => $details === null ? null : [
                "condition" => $details->condition()?->value,
                "in_library_since" => $date?->toArray(),
                "acquisition_method" => $details->acquisitionMethod()?->value,
                "acquired_via" => $details->acquiredVia(),
                "paid_amount" => $amount === null ? null : [
                    "decimal" => $amount->decimal(),
                    "currency" => $amount->currency()->value(),
                ],
                "signed" => $details->signed(),
                "signed_by" => $details->signedBy(),
                "copy_limitation" => $details->copyLimitation(),
                "dust_jacket" => $details->dustJacket()?->value,
                "inscription" => $details->inscription(),
                "provenance" => $details->provenance(),
                "completeness" => $details->completeness(),
            ],
            "approved_existing_item_id" =>
                $this->approvedExistingItemId?->value(),
            "preservation" => $this->preservation?->canonicalPayload(),
        ];
    }
}
