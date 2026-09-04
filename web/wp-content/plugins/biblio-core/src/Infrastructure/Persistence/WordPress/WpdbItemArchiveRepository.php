<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\{EditionId,InventoryNumber,Item,ItemArchivePeriod,ItemArchiveReason,ItemId,ItemStatus,ItemVersion,LocationId,WritableItemArchiveRepository};
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use wpdb;

final readonly class WpdbItemArchiveRepository implements WritableItemArchiveRepository
{
    public function __construct(private wpdb $database, private CoreTableNames $tables) {}

    public function findItemForUpdate(ItemId $itemId, LibraryId $libraryId): ?Item
    {
        $table = $this->tables->items();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT item_id,library_id,edition_id,item_status,inventory_number,location_id,item_version "
                . "FROM `{$table}` WHERE item_id=%s AND library_id=%s FOR UPDATE",
            $itemId->value(),
            $libraryId->value()
        ));
        return $row === null ? null : $this->hydrateItem($row);
    }

    public function saveArchive(Item $replacement, ItemVersion $expectedVersion, ItemArchivePeriod $period): bool
    {
        $this->assertTransition($replacement, $expectedVersion, $period, ItemStatus::Archived);
        $items = $this->tables->items();
        $result = $this->database->query($this->database->prepare(
            "UPDATE `{$items}` SET item_status=%s,item_version=%d WHERE item_id=%s AND library_id=%s AND item_version=%d AND item_status=%s",
            $replacement->status()->value,
            $replacement->version()->value(),
            $replacement->id()->value(),
            $replacement->libraryId()->value(),
            $expectedVersion->value(),
            ItemStatus::Active->value
        ));
        if ($result === false) { throw WpdbErrorTranslator::writeFailure("Could not archive Item.", $this->database->last_error); }
        if ($result !== 1) { return false; }

        $history = $this->tables->itemArchivePeriods();
        $inserted = $this->database->insert($history, [
            "library_id" => $period->libraryId()->value(),
            "item_id" => $period->itemId()->value(),
            "archive_version" => $period->archiveVersion()->value(),
            "archive_reason" => $period->reason()->value,
            "archived_at" => $this->formatInstant($period->archivedAt()),
        ], ["%s", "%s", "%d", "%s", "%s"]);
        if ($inserted !== 1) { throw WpdbErrorTranslator::writeFailure("Could not persist Item archive period.", $this->database->last_error); }
        return true;
    }

    public function saveRestore(Item $replacement, ItemVersion $expectedVersion, ItemArchivePeriod $period): bool
    {
        $this->assertTransition($replacement, $expectedVersion, $period, ItemStatus::Active);
        if ($period->isOpen() || $period->restoreVersion()?->value() !== $replacement->version()->value()) {
            throw new PersistenceException("Closed Item archive period is required.", failureReason: FailureReason::PersistenceWriteFailed);
        }
        $items = $this->tables->items();
        $result = $this->database->query($this->database->prepare(
            "UPDATE `{$items}` SET item_status=%s,item_version=%d WHERE item_id=%s AND library_id=%s AND item_version=%d AND item_status=%s",
            $replacement->status()->value,
            $replacement->version()->value(),
            $replacement->id()->value(),
            $replacement->libraryId()->value(),
            $expectedVersion->value(),
            ItemStatus::Archived->value
        ));
        if ($result === false) { throw WpdbErrorTranslator::writeFailure("Could not restore Item.", $this->database->last_error); }
        if ($result !== 1) { return false; }

        $history = $this->tables->itemArchivePeriods();
        $closed = $this->database->query($this->database->prepare(
            "UPDATE `{$history}` SET restore_version=%d,restored_at=%s WHERE library_id=%s AND item_id=%s AND archive_version=%d AND restored_at IS NULL",
            $replacement->version()->value(),
            $this->formatInstant($period->restoredAt() ?? throw new PersistenceException("Restore timestamp is required.")),
            $replacement->libraryId()->value(),
            $replacement->id()->value(),
            $period->archiveVersion()->value()
        ));
        if ($closed !== 1) { throw WpdbErrorTranslator::writeFailure("Could not close Item archive period.", $this->database->last_error); }
        return true;
    }

    public function openPeriod(ItemId $itemId, LibraryId $libraryId): ?ItemArchivePeriod
    {
        $table = $this->tables->itemArchivePeriods();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT library_id,item_id,archive_version,archive_reason,archived_at,restore_version,restored_at FROM `{$table}` "
                . "WHERE library_id=%s AND item_id=%s AND restored_at IS NULL FOR UPDATE",
            $libraryId->value(),
            $itemId->value()
        ));
        return $row === null ? null : $this->hydratePeriod($row);
    }

    public function periodsForItems(LibraryId $libraryId, array $itemIds): array
    {
        $result = [];
        foreach ($itemIds as $itemId) { $result[$itemId->value()] = []; }
        if ($itemIds === []) { return $result; }
        $table = $this->tables->itemArchivePeriods();
        $placeholders = implode(",", array_fill(0, count($itemIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT library_id,item_id,archive_version,archive_reason,archived_at,restore_version,restored_at FROM `{$table}` "
                . "WHERE library_id=%s AND item_id IN ({$placeholders}) ORDER BY item_id,archive_version",
            $libraryId->value(),
            ...array_map(static fn (ItemId $id): string => $id->value(), $itemIds)
        ));
        try {
            foreach ($rows as $row) { $result[(string) $row->item_id][] = $this->hydratePeriod($row); }
            return $result;
        } catch (Throwable $exception) {
            throw new PersistenceException("Stored Item archive data is invalid.", 0, $exception, FailureReason::PersistenceReadFailed);
        }
    }

    private function assertTransition(Item $item, ItemVersion $expected, ItemArchivePeriod $period, ItemStatus $status): void
    {
        if (!$item->libraryId()->equals($period->libraryId()) || !$item->id()->equals($period->itemId())
            || $item->status() !== $status || $item->version()->value() !== $expected->value() + 1) {
            throw new PersistenceException("Invalid Item archive transition.", failureReason: FailureReason::PersistenceWriteFailed);
        }
    }

    private function hydrateItem(object $row): Item
    {
        try {
            return new Item(new ItemId((string) $row->item_id), new LibraryId((string) $row->library_id),
                new EditionId((string) $row->edition_id), ItemStatus::from((string) $row->item_status),
                $row->inventory_number === null ? null : new InventoryNumber((string) $row->inventory_number),
                $row->location_id === null ? null : new LocationId((string) $row->location_id), new ItemVersion((int) $row->item_version));
        } catch (Throwable $exception) { throw new PersistenceException("Stored Item data is invalid.", 0, $exception, FailureReason::PersistenceReadFailed); }
    }

    private function hydratePeriod(object $row): ItemArchivePeriod
    {
        return new ItemArchivePeriod(new LibraryId((string) $row->library_id), new ItemId((string) $row->item_id),
            new ItemVersion((int) $row->archive_version), ItemArchiveReason::from((string) $row->archive_reason),
            $this->hydrateInstant($row->archived_at), $row->restore_version === null ? null : new ItemVersion((int) $row->restore_version),
            $row->restored_at === null ? null : $this->hydrateInstant($row->restored_at));
    }

    private function formatInstant(DateTimeImmutable $date): string { return $date->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d H:i:s.u"); }
    private function hydrateInstant(mixed $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat("!Y-m-d H:i:s.u", (string) $value, new DateTimeZone("UTC"));
        if (!$date instanceof DateTimeImmutable) { throw new PersistenceException("Stored Item archive timestamp is invalid.", failureReason: FailureReason::PersistenceReadFailed); }
        return $date;
    }
}
