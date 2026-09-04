<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Collections\{CollectionDescription,CollectionId,CollectionItemPosition,CollectionMembership,CollectionMembershipEndReason,CollectionMembershipId,CollectionMembershipStatus,CollectionName,CollectionPosition,CollectionRepository,CollectionStatus,CollectionVersion,LibraryCollection,NormalizedCollectionName,WritableCollectionRepository};
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use wpdb;

final readonly class WpdbCollectionRepository implements WritableCollectionRepository
{
    public function __construct(private wpdb $database, private CoreTableNames $tables) {}

    public function nextPositionForUpdate(LibraryId $libraryId): CollectionPosition
    {
        $table = $this->tables->collections();
        $maximum = (int) $this->database->get_var($this->database->prepare(
            "SELECT COALESCE(MAX(collection_position),0) FROM `{$table}` WHERE library_id=%s AND collection_status='active'",
            $libraryId->value()
        ));
        return new CollectionPosition($maximum + 1);
    }

    public function add(LibraryCollection $collection): void
    {
        $result = $this->database->insert($this->tables->collections(), $this->collectionData($collection), ["%s", "%s", "%s", "%s", "%s", "%s", "%d", "%d", "%s", "%s"]);
        if ($result !== 1) { throw WpdbErrorTranslator::writeFailure("Could not create Collection.", $this->database->last_error); }
    }

    public function findForUpdate(LibraryId $libraryId, CollectionId $collectionId): ?LibraryCollection
    {
        $table = $this->tables->collections();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT library_id,collection_id,collection_name,normalized_name,description,collection_status,collection_position,collection_version,created_at,updated_at FROM `{$table}` WHERE library_id=%s AND collection_id=%s FOR UPDATE",
            $libraryId->value(), $collectionId->value()
        ));
        return $row === null ? null : $this->hydrateCollection($row);
    }

    public function activeByNormalizedNameForUpdate(LibraryId $libraryId, NormalizedCollectionName $name): ?LibraryCollection
    {
        $table = $this->tables->collections();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT library_id,collection_id,collection_name,normalized_name,description,collection_status,collection_position,collection_version,created_at,updated_at FROM `{$table}` WHERE library_id=%s AND collection_status='active' AND normalized_name=%s FOR UPDATE",
            $libraryId->value(), $name->value()
        ));
        return $row === null ? null : $this->hydrateCollection($row);
    }

    /** @return list<LibraryCollection> */
    public function activeForLibraryForUpdate(LibraryId $libraryId): array
    {
        return $this->collectionsForLibrary($libraryId, true);
    }

    public function replaceIfVersionMatches(LibraryCollection $replacement, CollectionVersion $expectedVersion): bool
    {
        $table = $this->tables->collections();
        $result = $this->database->update($table, [
            "collection_name" => $replacement->name()->value(),
            "normalized_name" => $replacement->normalizedName()->value(),
            "description" => $replacement->description()?->value(),
            "collection_status" => $replacement->status()->value,
            "collection_position" => $replacement->position()->value(),
            "collection_version" => $replacement->version()->value(),
            "updated_at" => $this->formatInstant($replacement->updatedAt()),
        ], [
            "library_id" => $replacement->libraryId()->value(),
            "collection_id" => $replacement->id()->value(),
            "collection_version" => $expectedVersion->value(),
        ], ["%s", "%s", "%s", "%s", "%d", "%d", "%s"], ["%s", "%s", "%d"]);
        if ($result === false) { throw WpdbErrorTranslator::writeFailure("Could not update Collection.", $this->database->last_error); }
        return $result === 1;
    }

    /** @return list<CollectionMembership> */
    public function activeMembershipsForUpdate(LibraryId $libraryId, CollectionId $collectionId): array
    {
        $table = $this->tables->collectionMemberships();
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT library_id,membership_id,collection_id,item_id,membership_status,item_position,added_at,ended_at,end_reason FROM `{$table}` WHERE library_id=%s AND collection_id=%s AND membership_status='active' ORDER BY item_position,item_id,membership_id FOR UPDATE",
            $libraryId->value(), $collectionId->value()
        ));
        return $this->hydrateMemberships($rows);
    }

    public function addMembership(CollectionMembership $membership): void
    {
        $result = $this->database->insert($this->tables->collectionMemberships(), $this->membershipData($membership), ["%s", "%s", "%s", "%s", "%s", "%d", "%s", "%s", "%s"]);
        if ($result !== 1) { throw WpdbErrorTranslator::writeFailure("Could not create Collection membership.", $this->database->last_error); }
    }

    public function replaceMembership(CollectionMembership $membership): void
    {
        $result = $this->database->update($this->tables->collectionMemberships(), [
            "membership_status" => $membership->status()->value,
            "item_position" => $membership->position()->value(),
            "ended_at" => $membership->endedAt() === null ? null : $this->formatInstant($membership->endedAt()),
            "end_reason" => $membership->endReason()?->value,
        ], [
            "library_id" => $membership->libraryId()->value(),
            "membership_id" => $membership->id()->value(),
            "collection_id" => $membership->collectionId()->value(),
            "item_id" => $membership->itemId()->value(),
        ], ["%s", "%d", "%s", "%s"], ["%s", "%s", "%s", "%s"]);
        if ($result === false) { throw WpdbErrorTranslator::writeFailure("Could not update Collection membership.", $this->database->last_error); }
        if ($result !== 1) { throw new PersistenceException("Collection membership update did not match exactly one row.", failureReason: FailureReason::PersistenceWriteFailed); }
    }

    public function deactivateForArchivedItem(LibraryId $libraryId, ItemId $itemId, DateTimeImmutable $archivedAt): void
    {
        $table = $this->tables->collectionMemberships();
        $result = $this->database->query($this->database->prepare(
            "UPDATE `{$table}` SET membership_status='inactive',ended_at=%s,end_reason='item_archived' WHERE library_id=%s AND item_id=%s AND membership_status='active'",
            $this->formatInstant($archivedAt), $libraryId->value(), $itemId->value()
        ));
        if ($result === false) { throw WpdbErrorTranslator::writeFailure("Could not retain archived Item Collection membership history.", $this->database->last_error); }
    }

    /** @return list<LibraryCollection> */
    public function activeForLibrary(LibraryId $libraryId): array
    {
        return $this->collectionsForLibrary($libraryId, false);
    }

    /**
     * @param list<CollectionId> $collectionIds
     * @return array<string, LibraryCollection|null>
     */
    public function findManyInLibrary(LibraryId $libraryId, array $collectionIds): array
    {
        $result = [];
        foreach ($collectionIds as $id) { $result[$id->value()] = null; }
        if ($collectionIds === []) { return $result; }
        $table = $this->tables->collections();
        $placeholders = implode(',', array_fill(0, count($collectionIds), '%s'));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT library_id,collection_id,collection_name,normalized_name,description,collection_status,collection_position,collection_version,created_at,updated_at FROM `{$table}` WHERE library_id=%s AND collection_id IN ({$placeholders}) ORDER BY collection_id",
            $libraryId->value(), ...array_map(static fn (CollectionId $id): string => $id->value(), $collectionIds)
        ));
        foreach ($rows as $row) { $result[(string) $row->collection_id] = $this->hydrateCollection($row); }
        return $result;
    }

    /**
     * @param list<ItemId> $itemIds
     * @return array<string, list<CollectionId>>
     */
    public function activeCollectionIdsForItems(LibraryId $libraryId, array $itemIds): array
    {
        $result = [];
        foreach ($itemIds as $id) { $result[$id->value()] = []; }
        if ($itemIds === []) { return $result; }
        [$sql, $values] = $this->activeMembershipQuery($libraryId, 'm.item_id', $itemIds, 'm.item_id,c.collection_position,c.collection_id');
        $rows = $this->database->get_results($this->database->prepare("SELECT m.item_id,c.collection_id {$sql}", ...$values));
        foreach ($rows as $row) { $result[(string) $row->item_id][] = new CollectionId((string) $row->collection_id); }
        return $result;
    }

    /**
     * @param list<CollectionId> $collectionIds
     * @return array<string, list<ItemId>>
     */
    public function activeItemIdsForCollections(LibraryId $libraryId, array $collectionIds): array
    {
        $result = [];
        foreach ($collectionIds as $id) { $result[$id->value()] = []; }
        if ($collectionIds === []) { return $result; }
        [$sql, $values] = $this->activeMembershipQuery($libraryId, 'm.collection_id', $collectionIds, 'm.collection_id,m.item_position,m.item_id,m.membership_id');
        $rows = $this->database->get_results($this->database->prepare("SELECT m.collection_id,m.item_id {$sql}", ...$values));
        foreach ($rows as $row) { $result[(string) $row->collection_id][] = new ItemId((string) $row->item_id); }
        return $result;
    }

    /**
     * @param list<CollectionId> $collectionIds
     * @return array<string, int>
     */
    public function activeCountsForCollections(LibraryId $libraryId, array $collectionIds): array
    {
        $result = [];
        foreach ($collectionIds as $id) { $result[$id->value()] = 0; }
        if ($collectionIds === []) { return $result; }
        $memberships = $this->tables->collectionMemberships();
        $collections = $this->tables->collections();
        $items = $this->tables->items();
        $placeholders = implode(',', array_fill(0, count($collectionIds), '%s'));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT m.collection_id,COUNT(*) membership_count FROM `{$memberships}` m INNER JOIN `{$collections}` c ON c.library_id=m.library_id AND c.collection_id=m.collection_id INNER JOIN `{$items}` i ON i.library_id=m.library_id AND i.item_id=m.item_id WHERE m.library_id=%s AND m.collection_id IN ({$placeholders}) AND m.membership_status='active' AND c.collection_status='active' AND i.item_status='active' GROUP BY m.collection_id ORDER BY m.collection_id",
            $libraryId->value(), ...array_map(static fn (CollectionId $id): string => $id->value(), $collectionIds)
        ));
        foreach ($rows as $row) { $result[(string) $row->collection_id] = (int) $row->membership_count; }
        return $result;
    }

    /**
     * @param list<ItemId> $itemIds
     * @return array<string, list<CollectionId>>
     */
    public function previousCollectionIdsForArchivedItems(LibraryId $libraryId, array $itemIds): array
    {
        $result = [];
        foreach ($itemIds as $id) { $result[$id->value()] = []; }
        if ($itemIds === []) { return $result; }
        $memberships = $this->tables->collectionMemberships();
        $items = $this->tables->items();
        $placeholders = implode(',', array_fill(0, count($itemIds), '%s'));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT DISTINCT m.item_id,m.collection_id FROM `{$memberships}` m INNER JOIN `{$items}` i ON i.library_id=m.library_id AND i.item_id=m.item_id WHERE m.library_id=%s AND m.item_id IN ({$placeholders}) AND m.membership_status='inactive' AND m.end_reason='item_archived' AND i.item_status='archived' ORDER BY m.item_id,m.collection_id",
            $libraryId->value(), ...array_map(static fn (ItemId $id): string => $id->value(), $itemIds)
        ));
        foreach ($rows as $row) { $result[(string) $row->item_id][] = new CollectionId((string) $row->collection_id); }
        return $result;
    }

    /** @return list<LibraryCollection> */
    private function collectionsForLibrary(LibraryId $libraryId, bool $forUpdate): array
    {
        $table = $this->tables->collections();
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT library_id,collection_id,collection_name,normalized_name,description,collection_status,collection_position,collection_version,created_at,updated_at FROM `{$table}` WHERE library_id=%s AND collection_status='active' ORDER BY collection_position,collection_id" . ($forUpdate ? ' FOR UPDATE' : ''),
            $libraryId->value()
        ));
        return array_map(fn (object $row): LibraryCollection => $this->hydrateCollection($row), $rows);
    }

    /**
     * @param list<CollectionId>|list<ItemId> $ids
     * @return array{string, list<string>}
     */
    private function activeMembershipQuery(LibraryId $libraryId, string $column, array $ids, string $order): array
    {
        $memberships = $this->tables->collectionMemberships();
        $collections = $this->tables->collections();
        $items = $this->tables->items();
        $placeholders = implode(',', array_fill(0, count($ids), '%s'));
        return [
            "FROM `{$memberships}` m INNER JOIN `{$collections}` c ON c.library_id=m.library_id AND c.collection_id=m.collection_id INNER JOIN `{$items}` i ON i.library_id=m.library_id AND i.item_id=m.item_id WHERE m.library_id=%s AND {$column} IN ({$placeholders}) AND m.membership_status='active' AND c.collection_status='active' AND i.item_status='active' ORDER BY {$order}",
            [$libraryId->value(), ...array_map(static fn (object $id): string => $id->value(), $ids)],
        ];
    }

    /** @return array<string,mixed> */
    private function collectionData(LibraryCollection $collection): array
    {
        return [
            "library_id" => $collection->libraryId()->value(), "collection_id" => $collection->id()->value(),
            "collection_name" => $collection->name()->value(), "normalized_name" => $collection->normalizedName()->value(),
            "description" => $collection->description()?->value(), "collection_status" => $collection->status()->value,
            "collection_position" => $collection->position()->value(), "collection_version" => $collection->version()->value(),
            "created_at" => $this->formatInstant($collection->createdAt()), "updated_at" => $this->formatInstant($collection->updatedAt()),
        ];
    }

    /** @return array<string,mixed> */
    private function membershipData(CollectionMembership $membership): array
    {
        return [
            "library_id" => $membership->libraryId()->value(), "membership_id" => $membership->id()->value(),
            "collection_id" => $membership->collectionId()->value(), "item_id" => $membership->itemId()->value(),
            "membership_status" => $membership->status()->value, "item_position" => $membership->position()->value(),
            "added_at" => $this->formatInstant($membership->addedAt()),
            "ended_at" => $membership->endedAt() === null ? null : $this->formatInstant($membership->endedAt()),
            "end_reason" => $membership->endReason()?->value,
        ];
    }

    private function hydrateCollection(object $row): LibraryCollection
    {
        try {
            return new LibraryCollection(new CollectionId((string) $row->collection_id), new LibraryId((string) $row->library_id), new CollectionName((string) $row->collection_name), new NormalizedCollectionName((string) $row->normalized_name), $row->description === null ? null : new CollectionDescription((string) $row->description), CollectionStatus::from((string) $row->collection_status), new CollectionPosition((int) $row->collection_position), new CollectionVersion((int) $row->collection_version), $this->hydrateInstant($row->created_at), $this->hydrateInstant($row->updated_at));
        } catch (Throwable $exception) { throw new PersistenceException("Stored Collection data is invalid.", 0, $exception, FailureReason::PersistenceReadFailed); }
    }

    /**
     * @param list<object> $rows
     * @return list<CollectionMembership>
     */
    private function hydrateMemberships(array $rows): array
    {
        try {
            return array_map(fn (object $row): CollectionMembership => new CollectionMembership(new CollectionMembershipId((string) $row->membership_id), new LibraryId((string) $row->library_id), new CollectionId((string) $row->collection_id), new ItemId((string) $row->item_id), CollectionMembershipStatus::from((string) $row->membership_status), new CollectionItemPosition((int) $row->item_position), $this->hydrateInstant($row->added_at), $row->ended_at === null ? null : $this->hydrateInstant($row->ended_at), $row->end_reason === null ? null : CollectionMembershipEndReason::from((string) $row->end_reason)), $rows);
        } catch (Throwable $exception) { throw new PersistenceException("Stored Collection membership data is invalid.", 0, $exception, FailureReason::PersistenceReadFailed); }
    }

    private function formatInstant(DateTimeImmutable $date): string { return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
    private function hydrateInstant(mixed $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', (string) $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) { throw new PersistenceException("Stored Collection timestamp is invalid.", failureReason: FailureReason::PersistenceReadFailed); }
        return $date;
    }
}
