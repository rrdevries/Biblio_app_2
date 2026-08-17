<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\CatalogRecordAlreadyExists;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\WritableItemRepository;
use Biblio\Core\Catalog\ItemStatus;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use Throwable;
use wpdb;

final readonly class WpdbItemRepository implements WritableItemRepository
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
                ],
                ["%s", "%s", "%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result !== 1) {
            $conflict = WpdbErrorTranslator::conflict(
                $this->database->last_error
            );

            if ($conflict?->constraintName() === "PRIMARY") {
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
            "SELECT item_id, library_id, edition_id, item_status "
            . "FROM `{$table}` WHERE item_id = %s AND library_id = %s",
            $itemId->value(),
            $libraryId->value()
        ));

        if ($row === null) {
            return null;
        }

        try {
            return new Item(
                new ItemId($row->item_id),
                new LibraryId($row->library_id),
                new EditionId($row->edition_id),
                ItemStatus::from($row->item_status)
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Item data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }
}
