<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\{EditionId,WorkId};
use Biblio\Core\Exception\{FailureReason,TransactionException,ValidationException};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Wishlist\{WishlistEntry,WishlistEntryId,WishlistEntryIdCollision,WishlistIntentConflict,WishlistRemovalReason,WishlistTargetType,WishlistWorkState,WritableWishlistRepository};
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use wpdb;

final readonly class WpdbWishlistRepository implements WritableWishlistRepository
{
    private const DATE_FORMAT = "Y-m-d H:i:s.u";
    private WpdbTransactionConnection $connection;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
        $this->connection = new WpdbTransactionConnection($database);
    }

    public function lockOrCreateWorkState(
        UserId $ownerUserId,
        WorkId $workId,
        WishlistTargetType $initialType,
        DateTimeImmutable $now
    ): WishlistWorkState {
        $this->assertTransaction();
        $states = $this->tables->wishlistWorkStates();
        $instant = $this->format($now);
        $result = $this->database->query($this->database->prepare(
            "INSERT INTO `{$states}` "
                . "(user_id,work_id,target_type,created_at,updated_at) "
                . "VALUES (%s,%s,%s,%s,%s) "
                . "ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)",
            $ownerUserId->value(),
            $workId->value(),
            $initialType->value,
            $instant,
            $instant
        ));
        if ($result === false) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not provision Wishlist Work state.",
                $this->database->last_error
            );
        }

        return $this->lockState($ownerUserId, $workId)
            ?? throw new PersistenceException(
                "Could not lock Wishlist Work state.",
                failureReason: FailureReason::PersistenceReadFailed
            );
    }

    public function lockExistingWorkState(
        UserId $ownerUserId,
        WorkId $workId
    ): ?WishlistWorkState {
        $this->assertTransaction();
        return $this->lockState($ownerUserId, $workId);
    }

    public function entriesForUserAndWork(
        UserId $ownerUserId,
        WorkId $workId
    ): array {
        $this->assertTransaction();
        $entries = $this->tables->wishlistEntries();
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT wishlist_entry_id,user_id,work_id,edition_id,created_at,updated_at "
                . "FROM `{$entries}` WHERE user_id=%s AND work_id=%s "
                . "ORDER BY created_at,wishlist_entry_id FOR UPDATE",
            $ownerUserId->value(),
            $workId->value()
        ));

        return $this->hydrateMany($rows);
    }

    public function findForUser(
        WishlistEntryId $entryId,
        UserId $ownerUserId
    ): ?WishlistEntry {
        $entries = $this->tables->wishlistEntries();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT wishlist_entry_id,user_id,work_id,edition_id,created_at,updated_at "
                . "FROM `{$entries}` WHERE wishlist_entry_id=%s AND user_id=%s",
            $entryId->value(),
            $ownerUserId->value()
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    public function findEditionEntry(
        UserId $ownerUserId,
        EditionId $editionId
    ): ?WishlistEntry {
        $entries = $this->tables->wishlistEntries();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT wishlist_entry_id,user_id,work_id,edition_id,created_at,updated_at "
                . "FROM `{$entries}` WHERE user_id=%s AND edition_id=%s FOR UPDATE",
            $ownerUserId->value(),
            $editionId->value()
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    public function lockForUser(
        WishlistEntryId $entryId,
        UserId $ownerUserId
    ): ?WishlistEntry {
        $this->assertTransaction();
        $entries = $this->tables->wishlistEntries();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT wishlist_entry_id,user_id,work_id,edition_id,created_at,updated_at "
                . "FROM `{$entries}` WHERE wishlist_entry_id=%s AND user_id=%s FOR UPDATE",
            $entryId->value(),
            $ownerUserId->value()
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    public function add(WishlistEntry $entry): void
    {
        $this->assertTransaction();
        if ($this->historyContains($entry->id())) {
            throw new WishlistEntryIdCollision(
                WpdbErrorTranslator::diagnostic(
                    "Wishlist Entry ID allocation",
                    "ID already belongs to removal history"
                )
            );
        }
        $previous = $this->database->suppress_errors(true);
        try {
            $result = $this->insertEntry($entry);
        } finally {
            $this->database->suppress_errors($previous);
        }
        if ($result === 1) {
            return;
        }

        $conflict = WpdbErrorTranslator::conflict($this->database->last_error);
        if ($conflict?->constraintName() === "PRIMARY") {
            throw new WishlistEntryIdCollision(
                WpdbErrorTranslator::diagnostic(
                    "Wishlist Entry insert",
                    $this->database->last_error
                )
            );
        }
        if ($conflict !== null) {
            throw new WishlistIntentConflict();
        }
        throw WpdbErrorTranslator::writeFailure(
            "Could not persist Wishlist Entry.",
            $this->database->last_error
        );
    }

    public function refineWorkOnly(
        WishlistEntry $current,
        WishlistEntry $replacement,
        DateTimeImmutable $updatedAt
    ): void {
        $this->assertTransaction();
        if (
            !$current->id()->equals($replacement->id())
            || !$current->ownerUserId()->equals($replacement->ownerUserId())
            || !$current->workId()->equals($replacement->workId())
            || $current->targetType() !== WishlistTargetType::WorkOnly
            || $replacement->targetType() !== WishlistTargetType::EditionSpecific
        ) {
            throw new ValidationException(
                "Wishlist refinement must preserve entry, owner and Work."
            );
        }

        $entries = $this->tables->wishlistEntries();
        $states = $this->tables->wishlistWorkStates();
        $deleted = $this->database->query($this->database->prepare(
            "DELETE FROM `{$entries}` WHERE wishlist_entry_id=%s AND user_id=%s "
                . "AND work_id=%s AND target_type='work_only'",
            $current->id()->value(),
            $current->ownerUserId()->value(),
            $current->workId()->value()
        ));
        if ($deleted !== 1) {
            throw new WishlistIntentConflict();
        }
        $changed = $this->database->query($this->database->prepare(
            "UPDATE `{$states}` SET target_type='edition_specific',updated_at=%s "
                . "WHERE user_id=%s AND work_id=%s AND target_type='work_only'",
            $this->format($updatedAt),
            $current->ownerUserId()->value(),
            $current->workId()->value()
        ));
        if ($changed !== 1) {
            throw new WishlistIntentConflict();
        }
        if ($this->insertEntry($replacement) !== 1) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist refined Wishlist Entry.",
                $this->database->last_error
            );
        }
    }

    public function remove(
        WishlistEntry $entry,
        WishlistRemovalReason $reason,
        DateTimeImmutable $removedAt
    ): void
    {
        $this->assertTransaction();
        $entries = $this->tables->wishlistEntries();
        $states = $this->tables->wishlistWorkStates();
        if ($removedAt < $entry->updatedAt()) {
            throw new ValidationException(
                "Wishlist removal time must not precede its last update."
            );
        }
        $history = $this->tables->wishlistEntryHistory();
        $archived = $this->database->insert(
            $history,
            [
                "wishlist_entry_id" => $entry->id()->value(),
                "user_id" => $entry->ownerUserId()->value(),
                "work_id" => $entry->workId()->value(),
                "target_type" => $entry->targetType()->value,
                "edition_id" => $entry->editionId()?->value(),
                "created_at" => $this->format($entry->createdAt()),
                "updated_at" => $this->format($entry->updatedAt()),
                "removed_at" => $this->format($removedAt),
                "removal_reason" => $reason->value,
            ],
            ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s"]
        );
        if ($archived !== 1) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not preserve Wishlist removal history.",
                $this->database->last_error
            );
        }
        $deleted = $this->database->query($this->database->prepare(
            "DELETE FROM `{$entries}` WHERE wishlist_entry_id=%s AND user_id=%s "
                . "AND work_id=%s",
            $entry->id()->value(),
            $entry->ownerUserId()->value(),
            $entry->workId()->value()
        ));
        if ($deleted !== 1) {
            throw new WishlistIntentConflict();
        }
        $remaining = (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$entries}` WHERE user_id=%s AND work_id=%s",
            $entry->ownerUserId()->value(),
            $entry->workId()->value()
        ));
        if ($remaining !== 0) {
            return;
        }
        $stateDeleted = $this->database->query($this->database->prepare(
            "DELETE FROM `{$states}` WHERE user_id=%s AND work_id=%s",
            $entry->ownerUserId()->value(),
            $entry->workId()->value()
        ));
        if ($stateDeleted !== 1) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not remove empty Wishlist Work state.",
                $this->database->last_error
            );
        }
    }

    private function lockState(
        UserId $ownerUserId,
        WorkId $workId
    ): ?WishlistWorkState {
        $states = $this->tables->wishlistWorkStates();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT user_id,work_id,target_type FROM `{$states}` "
                . "WHERE user_id=%s AND work_id=%s FOR UPDATE",
            $ownerUserId->value(),
            $workId->value()
        ));
        if ($row === null) {
            return null;
        }

        try {
            return new WishlistWorkState(
                new UserId((string) $row->user_id),
                new WorkId((string) $row->work_id),
                WishlistTargetType::from((string) $row->target_type)
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Wishlist Work state is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    private function insertEntry(WishlistEntry $entry): int|false
    {
        return $this->database->insert(
            $this->tables->wishlistEntries(),
            [
                "wishlist_entry_id" => $entry->id()->value(),
                "user_id" => $entry->ownerUserId()->value(),
                "work_id" => $entry->workId()->value(),
                "target_type" => $entry->targetType()->value,
                "edition_id" => $entry->editionId()?->value(),
                "created_at" => $this->format($entry->createdAt()),
                "updated_at" => $this->format($entry->updatedAt()),
            ],
            ["%s", "%s", "%s", "%s", "%s", "%s", "%s"]
        );
    }

    private function historyContains(WishlistEntryId $entryId): bool
    {
        $history = $this->tables->wishlistEntryHistory();
        return $this->database->get_var($this->database->prepare(
            "SELECT wishlist_entry_id FROM `{$history}` "
                . "WHERE wishlist_entry_id=%s FOR UPDATE",
            $entryId->value()
        )) !== null;
    }

    /**
     * @param list<object> $rows
     * @return list<WishlistEntry>
     */
    private function hydrateMany(array $rows): array
    {
        return array_map($this->hydrate(...), $rows);
    }

    private function hydrate(object $row): WishlistEntry
    {
        try {
            return new WishlistEntry(
                new WishlistEntryId((string) $row->wishlist_entry_id),
                new UserId((string) $row->user_id),
                new WorkId((string) $row->work_id),
                $row->edition_id === null
                    ? null
                    : new EditionId((string) $row->edition_id),
                $this->hydrateDate((string) $row->created_at),
                $this->hydrateDate((string) $row->updated_at)
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Wishlist Entry is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    private function assertTransaction(): void
    {
        if ($this->connection->isTransactionActive() !== true) {
            throw new TransactionException(
                "Wishlist mutation requires an active transaction.",
                FailureReason::TransactionBeginFailed
            );
        }
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone("UTC"))->format(self::DATE_FORMAT);
    }

    private function hydrateDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            "!" . self::DATE_FORMAT,
            $value,
            new DateTimeZone("UTC")
        );
        if (!$date instanceof DateTimeImmutable) {
            throw new PersistenceException(
                "Stored Wishlist timestamp is invalid.",
                failureReason: FailureReason::PersistenceReadFailed
            );
        }
        return $date;
    }
}
