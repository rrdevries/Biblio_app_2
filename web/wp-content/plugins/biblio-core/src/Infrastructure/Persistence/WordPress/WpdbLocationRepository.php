<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\{ItemId,LibraryLocation,LocationId,WritableLocationRepository};
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use Throwable;
use wpdb;

final readonly class WpdbLocationRepository implements WritableLocationRepository
{
    public function __construct(private wpdb $database, private CoreTableNames $tables) {}

    public function save(LibraryLocation $location): void
    {
        $table = $this->tables->locations();
        $result = $this->database->query($this->database->prepare(
            "INSERT INTO `{$table}` (library_id,location_id,display_name) VALUES (%s,%s,%s) "
                . "ON DUPLICATE KEY UPDATE display_name=VALUES(display_name)",
            $location->libraryId()->value(),
            $location->id()->value(),
            $location->displayName()
        ));
        if ($result === false) {
            throw WpdbErrorTranslator::writeFailure("Could not persist Location.", $this->database->last_error);
        }
    }

    public function assignToItem(
        LibraryId $libraryId,
        ItemId $itemId,
        ?LocationId $locationId
    ): void {
        $previous = $this->database->suppress_errors(true);
        try {
            $result = $this->database->update(
                $this->tables->items(),
                ["location_id" => $locationId?->value()],
                ["library_id" => $libraryId->value(), "item_id" => $itemId->value()],
                ["%s"],
                ["%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previous);
        }
        if ($result === false) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist Item Location.",
                $this->database->last_error
            );
        }
        if ($result === 0) {
            $items = $this->tables->items();
            $exists = $this->database->get_var($this->database->prepare(
                "SELECT item_id FROM `{$items}` WHERE library_id=%s AND item_id=%s",
                $libraryId->value(),
                $itemId->value()
            ));
            if ($exists === null) {
                throw new PersistenceException(
                    "Library Item Location assignment is unavailable.",
                    0,
                    null,
                    FailureReason::PersistenceWriteFailed
                );
            }
        }
    }

    public function forLibrary(LibraryId $libraryId): array
    {
        $table = $this->tables->locations();
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT library_id,location_id,display_name FROM `{$table}` "
                . "WHERE library_id=%s ORDER BY display_name,location_id",
            $libraryId->value()
        ));
        return $this->hydrateMany($rows);
    }

    public function forItems(LibraryId $libraryId, array $itemIds): array
    {
        $result = [];
        foreach ($itemIds as $itemId) { $result[$itemId->value()] = null; }
        if ($itemIds === []) { return $result; }

        $items = $this->tables->items();
        $locations = $this->tables->locations();
        $placeholders = implode(",", array_fill(0, count($itemIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT i.item_id,l.library_id,l.location_id,l.display_name FROM `{$items}` i "
                . "LEFT JOIN `{$locations}` l ON l.library_id=i.library_id AND l.location_id=i.location_id "
                . "WHERE i.library_id=%s AND i.item_id IN ({$placeholders}) ORDER BY i.item_id",
            $libraryId->value(),
            ...array_map(static fn (ItemId $id): string => $id->value(), $itemIds)
        ));
        try {
            foreach ($rows as $row) {
                if ($row->location_id !== null) {
                    $result[(string) $row->item_id] = $this->hydrate($row);
                }
            }
            return $result;
        } catch (Throwable $exception) {
            throw new PersistenceException("Stored Location data is invalid.", 0, $exception, FailureReason::PersistenceReadFailed);
        }
    }

    public function itemIdsForLocations(LibraryId $libraryId, array $locationIds): array
    {
        $result = [];
        foreach ($locationIds as $locationId) { $result[$locationId->value()] = []; }
        if ($locationIds === []) { return $result; }

        $table = $this->tables->items();
        $placeholders = implode(",", array_fill(0, count($locationIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT location_id,item_id FROM `{$table}` WHERE library_id=%s "
                . "AND location_id IN ({$placeholders}) ORDER BY location_id,item_id",
            $libraryId->value(),
            ...array_map(static fn (LocationId $id): string => $id->value(), $locationIds)
        ));
        try {
            foreach ($rows as $row) {
                $result[(string) $row->location_id][] = new ItemId((string) $row->item_id);
            }
            return $result;
        } catch (Throwable $exception) {
            throw new PersistenceException("Stored Item Location data is invalid.", 0, $exception, FailureReason::PersistenceReadFailed);
        }
    }

    private function hydrate(object $row): LibraryLocation
    {
        return new LibraryLocation(new LocationId((string) $row->location_id), new LibraryId((string) $row->library_id), (string) $row->display_name);
    }

    /**
     * @param list<object> $rows
     * @return list<LibraryLocation>
     */
    private function hydrateMany(array $rows): array
    {
        try { return array_map(fn (object $row): LibraryLocation => $this->hydrate($row), $rows); }
        catch (Throwable $exception) {
            throw new PersistenceException("Stored Location data is invalid.", 0, $exception, FailureReason::PersistenceReadFailed);
        }
    }
}
