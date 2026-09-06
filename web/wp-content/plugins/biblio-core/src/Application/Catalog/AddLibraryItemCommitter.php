<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog;

use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitialization;
use Biblio\Core\Catalog\{EditionId,EditionIsbnMetadata,InventoryNumber,Item,ItemId,LocationId,WorkId};
use Biblio\Core\Library\LibraryId;

interface AddLibraryItemCommitter
{
    public function addForExistingEdition(
        LibraryId $libraryId,
        ItemId $itemId,
        EditionId $editionId,
        ?LibraryCatalogContextInitialization $classification = null,
        ?InventoryNumber $inventoryNumber = null,
        ?LocationId $locationId = null,
        ?AddLibraryItemTransactionParticipant $participant = null
    ): Item;

    public function addWithNewWorkAndEdition(
        LibraryId $libraryId,
        ItemId $itemId,
        WorkId $workId,
        string $editionTitle,
        EditionId $editionId,
        ?LibraryCatalogContextInitialization $classification = null,
        ?EditionIsbnMetadata $isbnMetadata = null,
        ?InventoryNumber $inventoryNumber = null,
        ?LocationId $locationId = null,
        ?AddLibraryItemTransactionParticipant $participant = null
    ): Item;

    public function addWithNewEditionForExistingWork(
        LibraryId $libraryId,
        ItemId $itemId,
        EditionId $editionId,
        WorkId $workId,
        string $editionTitle,
        ?LibraryCatalogContextInitialization $classification = null,
        ?EditionIsbnMetadata $isbnMetadata = null,
        ?InventoryNumber $inventoryNumber = null,
        ?LocationId $locationId = null,
        ?AddLibraryItemTransactionParticipant $participant = null
    ): Item;
}
