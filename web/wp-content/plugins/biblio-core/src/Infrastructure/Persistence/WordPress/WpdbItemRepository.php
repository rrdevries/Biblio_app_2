<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Metadata\AddBookExistingItem;
use Biblio\Core\Application\Metadata\AddBookExistingItemRepository;
use Biblio\Core\Catalog\CatalogRecordAlreadyExists;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\InventoryNumber;
use Biblio\Core\Catalog\LibraryLocation;
use Biblio\Core\Catalog\LocationId;
use Biblio\Core\Catalog\LibraryItemMetadataRepository;
use Biblio\Core\Catalog\WritableItemRepository;
use Biblio\Core\Catalog\ItemStatus;
use Biblio\Core\Catalog\ItemVersion;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use Throwable;
use wpdb;

final readonly class WpdbItemRepository implements
    WritableItemRepository,
    LibraryItemMetadataRepository,
    AddBookExistingItemRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
    }

    public function add(Item $item): void
    {
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $this->database->insert(
                $this->tableNames->items(),
                [
                    "item_id" => $item->id()->value(),
                    "library_id" => $item->libraryId()->value(),
                    "edition_id" => $item->editionId()->value(),
                    "item_status" => $item->status()->value,
                    "inventory_number" => $item->inventoryNumber()?->value(),
                    "location_id" => $item->locationId()?->value(),
                    "item_version" => $item->version()->value(),
                ],
                ["%s", "%s", "%s", "%s", "%s", "%s", "%d"]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result !== 1) {
            $conflict = WpdbErrorTranslator::conflict(
                $this->database->last_error
            );

            if (in_array(
                $conflict?->constraintName(),
                ["PRIMARY", "items_by_library_inventory_number"],
                true
            )) {
                throw new CatalogRecordAlreadyExists(
                    WpdbErrorTranslator::diagnostic(
                        "Item insert",
                        $this->database->last_error
                    )
                );
            }

            throw WpdbErrorTranslator::writeFailure(
                "Could not persist Item.",
                $this->database->last_error
            );
        }
    }

    public function findInLibrary(
        ItemId $itemId,
        LibraryId $libraryId
    ): ?Item {
        $table = $this->tableNames->items();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT item_id, library_id, edition_id, item_status, "
            . "inventory_number, location_id, item_version "
            . "FROM `{$table}` WHERE item_id = %s AND library_id = %s",
            $itemId->value(),
            $libraryId->value()
        ));

        if ($row === null) {
            return null;
        }

        try {
            return $this->hydrate($row);
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Item data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    public function findManyInLibrary(LibraryId $libraryId, array $itemIds): array
    {
        $result = [];
        foreach ($itemIds as $itemId) { $result[$itemId->value()] = null; }
        if ($itemIds === []) { return $result; }

        $table = $this->tableNames->items();
        $placeholders = implode(",", array_fill(0, count($itemIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT item_id,library_id,edition_id,item_status,inventory_number,location_id,item_version "
                . "FROM `{$table}` WHERE library_id=%s AND item_id IN ({$placeholders}) ORDER BY item_id",
            $libraryId->value(),
            ...array_map(static fn (ItemId $id): string => $id->value(), $itemIds)
        ));

        try {
            foreach ($rows as $row) { $result[(string) $row->item_id] = $this->hydrate($row); }
            return $result;
        } catch (Throwable $exception) {
            throw new PersistenceException("Stored Item data is invalid.", 0, $exception, FailureReason::PersistenceReadFailed);
        }
    }

    public function forEditionsInLibrary(
        LibraryId $libraryId,
        array $editionIds
    ): array {
        $result = [];
        foreach ($editionIds as $editionId) {
            $result[$editionId->value()] = [];
        }
        if ($editionIds === []) {
            return $result;
        }

        $items = $this->tableNames->items();
        $locations = $this->tableNames->locations();
        $placeholders = implode(",", array_fill(0, count($editionIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT i.edition_id,i.item_id,i.inventory_number,l.library_id,"
                . "l.location_id,l.display_name FROM `{$items}` i "
                . "LEFT JOIN `{$locations}` l "
                . "ON l.library_id=i.library_id "
                . "AND l.location_id=i.location_id "
                . "WHERE i.library_id=%s "
                . "AND i.edition_id IN ({$placeholders}) "
                . "ORDER BY i.edition_id,i.item_id",
            $libraryId->value(),
            ...array_map(
                static fn (EditionId $editionId): string =>
                    $editionId->value(),
                $editionIds
            )
        ));

        try {
            foreach ($rows as $row) {
                $result[(string) $row->edition_id][] =
                    new AddBookExistingItem(
                        new ItemId((string) $row->item_id),
                        $row->inventory_number === null
                            ? null
                            : new InventoryNumber(
                                (string) $row->inventory_number
                            ),
                        $row->location_id === null
                            ? null
                            : new LibraryLocation(
                                new LocationId((string) $row->location_id),
                                new LibraryId((string) $row->library_id),
                                (string) $row->display_name
                            )
                    );
            }

            return $result;
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Add Book Item context is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    public function inventoryNumbersForItems(
        LibraryId $libraryId,
        array $itemIds
    ): array {
        $result = [];
        foreach ($itemIds as $itemId) {
            $result[$itemId->value()] = null;
        }

        if ($itemIds === []) {
            return $result;
        }

        $table = $this->tableNames->items();
        $placeholders = implode(",", array_fill(0, count($itemIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT item_id, inventory_number FROM `{$table}` "
                . "WHERE library_id = %s AND item_id IN ({$placeholders}) "
                . "ORDER BY item_id",
            $libraryId->value(),
            ...array_map(
                static fn (ItemId $itemId): string => $itemId->value(),
                $itemIds
            )
        ));

        try {
            foreach ($rows as $row) {
                $result[(string) $row->item_id] =
                    $row->inventory_number === null
                        ? null
                        : new InventoryNumber(
                            (string) $row->inventory_number
                        );
            }

            return $result;
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Item inventory data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    private function hydrate(object $row): Item
    {
        return new Item(
            new ItemId((string) $row->item_id),
            new LibraryId((string) $row->library_id),
            new EditionId((string) $row->edition_id),
            ItemStatus::from((string) $row->item_status),
            $row->inventory_number === null ? null : new InventoryNumber((string) $row->inventory_number),
            $row->location_id === null ? null : new LocationId((string) $row->location_id),
            new ItemVersion((int) $row->item_version)
        );
    }
}
