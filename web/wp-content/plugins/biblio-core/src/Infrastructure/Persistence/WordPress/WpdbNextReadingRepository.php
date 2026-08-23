<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\{ItemId,WorkId};
use Biblio\Core\Exception\{FailureReason,TransactionException};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\NextReading\{NextReadingEntry,NextReadingEntryId,NextReadingEntryIdCollision,NextReadingList,NextReadingListVersion,NextReadingPosition,NextReadingTarget,NextReadingTargetDuplicate,NextReadingTargetType,NextReadingTargetUnavailable,WritableNextReadingRepository};
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use wpdb;

final readonly class WpdbNextReadingRepository implements WritableNextReadingRepository
{
    private const DATE_FORMAT = "Y-m-d H:i:s.u";
    private WpdbTransactionConnection $connection;

    public function __construct(private wpdb $database, private CoreTableNames $tables)
    {
        $this->connection = new WpdbTransactionConnection($database);
    }

    public function findForUser(UserId $userId, ?int $limit = null): NextReadingList
    {
        if ($limit !== null && $limit < 1) {
            throw new PersistenceException("Next Reading query limit must be positive.");
        }
        $lists = $this->tables->nextReadingLists();
        $version = $this->database->get_var($this->database->prepare(
            "SELECT list_version FROM `{$lists}` WHERE user_id=%s",
            $userId->value()
        ));
        if ($version === null) {
            return NextReadingList::empty($userId);
        }
        return new NextReadingList(
            $userId,
            new NextReadingListVersion((int) $version),
            $this->entries($userId, $limit)
        );
    }

    public function lockForUser(UserId $userId, DateTimeImmutable $now): NextReadingList
    {
        $this->assertTransaction();
        $lists = $this->tables->nextReadingLists();
        $instant = $this->format($now);
        $result = $this->database->query($this->database->prepare(
            "INSERT INTO `{$lists}` (user_id,list_version,created_at,updated_at) "
            . "VALUES (%s,1,%s,%s) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)",
            $userId->value(),
            $instant,
            $instant
        ));
        if ($result === false) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not provision Next Reading list state.",
                $this->database->last_error
            );
        }
        $version = $this->database->get_var($this->database->prepare(
            "SELECT list_version FROM `{$lists}` WHERE user_id=%s FOR UPDATE",
            $userId->value()
        ));
        if ($version === null) {
            throw new PersistenceException(
                "Could not lock Next Reading list state.",
                failureReason: FailureReason::PersistenceReadFailed
            );
        }
        return new NextReadingList(
            $userId,
            new NextReadingListVersion((int) $version),
            $this->entries($userId)
        );
    }

    public function append(
        UserId $userId,
        NextReadingEntry $entry,
        NextReadingListVersion $expectedVersion,
        NextReadingListVersion $nextVersion,
        DateTimeImmutable $updatedAt
    ): void {
        $this->assertTransaction();
        if (!$entry->userId()->equals($userId)
            || $nextVersion->value() !== $expectedVersion->value() + 1) {
            throw new PersistenceException(
                "Invalid Next Reading append state.",
                failureReason: FailureReason::PersistenceWriteFailed
            );
        }
        $this->lockAndAssertSource($userId, $entry->target());
        $target = $entry->target();
        $previous = $this->database->suppress_errors(true);
        try {
            $result = $this->database->insert(
                $this->tables->nextReadingEntries(),
                [
                    "entry_id" => $entry->id()->value(),
                    "user_id" => $userId->value(),
                    "work_id" => $target->workId()->value(),
                    "target_type" => $target->type()->value,
                    "source_id_snapshot" => $target->itemIdSnapshot()?->value()
                        ?? $target->externalLoanIdSnapshot()?->value(),
                    "source_library_id_snapshot" => $target->libraryIdSnapshot()?->value(),
                    "item_id" => $target->liveItemId()?->value(),
                    "external_loan_id" => $target->liveExternalLoanId()?->value(),
                    "position" => $entry->position()->value(),
                    "created_at" => $this->format($entry->createdAt()),
                ],
                ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%d", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previous);
        }
        if ($result !== 1) {
            $conflict = WpdbErrorTranslator::conflict($this->database->last_error);
            if ($conflict?->constraintName() === "PRIMARY") {
                throw new NextReadingEntryIdCollision();
            }
            if (in_array($conflict?->constraintName(), [
                "one_next_reading_work_target",
                "one_next_reading_item_target",
                "one_next_reading_external_target",
            ], true)) {
                throw new NextReadingTargetDuplicate();
            }
            throw WpdbErrorTranslator::writeFailure(
                "Could not append Next Reading Entry.",
                $this->database->last_error
            );
        }
        $this->advanceVersion($userId, $expectedVersion, $nextVersion, $updatedAt);
    }

    public function replaceEntries(
        UserId $userId,
        array $entries,
        NextReadingListVersion $expectedVersion,
        NextReadingListVersion $nextVersion,
        DateTimeImmutable $updatedAt
    ): void {
        $this->assertTransaction();
        if ($nextVersion->value() !== $expectedVersion->value() + 1) {
            throw new PersistenceException("Invalid Next Reading replacement version.");
        }
        $entryTable = $this->tables->nextReadingEntries();
        $ids = [];
        foreach ($entries as $offset => $entry) {
            if (!$entry->userId()->equals($userId) || $entry->position()->value() !== $offset + 1) {
                throw new PersistenceException("Invalid Next Reading replacement state.");
            }
            $ids[] = $entry->id()->value();
        }
        if ($ids === []) {
            $deleted = $this->database->query($this->database->prepare(
                "DELETE FROM `{$entryTable}` WHERE user_id=%s",
                $userId->value()
            ));
        } else {
            $placeholders = implode(",", array_fill(0, count($ids), "%s"));
            $deleted = $this->database->query($this->database->prepare(
                "DELETE FROM `{$entryTable}` WHERE user_id=%s AND entry_id NOT IN ({$placeholders})",
                $userId->value(),
                ...$ids
            ));
        }
        if ($deleted === false) {
            throw WpdbErrorTranslator::writeFailure("Could not remove Next Reading Entry.", $this->database->last_error);
        }

        if ($entries !== []) {
            $offset = count($entries) * 2 + 1;
            $shifted = $this->database->query($this->database->prepare(
                "UPDATE `{$entryTable}` SET position=position+%d WHERE user_id=%s",
                $offset,
                $userId->value()
            ));
            if ($shifted === false) {
                throw WpdbErrorTranslator::writeFailure("Could not stage Next Reading order.", $this->database->last_error);
            }
            foreach ($entries as $entry) {
                $written = $this->database->query($this->database->prepare(
                    "UPDATE `{$entryTable}` SET position=%d WHERE user_id=%s AND entry_id=%s",
                    $entry->position()->value(),
                    $userId->value(),
                    $entry->id()->value()
                ));
                if ($written !== 1) {
                    throw new PersistenceException(
                        "Could not persist complete owner-scoped Next Reading order.",
                        failureReason: FailureReason::PersistenceWriteFailed
                    );
                }
            }
        }
        $this->advanceVersion($userId, $expectedVersion, $nextVersion, $updatedAt);
    }

    /** @return list<NextReadingEntry> */
    private function entries(UserId $userId, ?int $limit = null): array
    {
        $table = $this->tables->nextReadingEntries();
        $sql = $this->database->prepare(
            "SELECT entry_id,user_id,work_id,target_type,source_id_snapshot,"
            . "source_library_id_snapshot,item_id,external_loan_id,position,created_at "
            . "FROM `{$table}` WHERE user_id=%s ORDER BY position ASC,entry_id ASC"
            . ($limit === null ? "" : " LIMIT %d"),
            ...($limit === null ? [$userId->value()] : [$userId->value(), $limit])
        );
        $rows = $this->database->get_results($sql);
        try {
            return array_map($this->hydrate(...), $rows);
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Next Reading data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    private function hydrate(object $row): NextReadingEntry
    {
        $workId = new WorkId((string) $row->work_id);
        $type = NextReadingTargetType::from((string) $row->target_type);
        $target = match ($type) {
            NextReadingTargetType::Work => NextReadingTarget::forWork($workId),
            NextReadingTargetType::LibraryItem => NextReadingTarget::forLibraryItem(
                $workId,
                new ItemId((string) $row->source_id_snapshot),
                new LibraryId((string) $row->source_library_id_snapshot),
                $row->item_id !== null
            ),
            NextReadingTargetType::ExternalLoan => NextReadingTarget::forExternalLoan(
                $workId,
                new ExternalLoanId((string) $row->source_id_snapshot),
                $row->external_loan_id !== null
            ),
        };
        return new NextReadingEntry(
            new NextReadingEntryId((string) $row->entry_id),
            new UserId((string) $row->user_id),
            $target,
            new NextReadingPosition((int) $row->position),
            $this->hydrateDate((string) $row->created_at)
        );
    }

    private function lockAndAssertSource(UserId $userId, NextReadingTarget $target): void
    {
        if ($target->type() === NextReadingTargetType::Work) {
            return;
        }
        if ($target->type() === NextReadingTargetType::LibraryItem) {
            $items = $this->tables->items();
            $editions = $this->tables->editions();
            $row = $this->database->get_row($this->database->prepare(
                "SELECT i.item_id FROM `{$items}` i INNER JOIN `{$editions}` e ON e.edition_id=i.edition_id "
                . "WHERE i.item_id=%s AND i.library_id=%s AND e.work_id=%s FOR UPDATE",
                $target->itemIdSnapshot()?->value(),
                $target->libraryIdSnapshot()?->value(),
                $target->workId()->value()
            ));
            if ($row === null) {
                throw new NextReadingTargetUnavailable();
            }
            return;
        }
        $loans = $this->tables->externalLoans();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT external_loan_id FROM `{$loans}` WHERE external_loan_id=%s AND user_id=%s AND work_id=%s FOR UPDATE",
            $target->externalLoanIdSnapshot()?->value(),
            $userId->value(),
            $target->workId()->value()
        ));
        if ($row === null) {
            throw new NextReadingTargetUnavailable();
        }
    }

    private function advanceVersion(
        UserId $userId,
        NextReadingListVersion $expected,
        NextReadingListVersion $next,
        DateTimeImmutable $updatedAt
    ): void {
        $lists = $this->tables->nextReadingLists();
        $result = $this->database->query($this->database->prepare(
            "UPDATE `{$lists}` SET list_version=%d,updated_at=%s WHERE user_id=%s AND list_version=%d",
            $next->value(),
            $this->format($updatedAt),
            $userId->value(),
            $expected->value()
        ));
        if ($result !== 1) {
            throw new PersistenceException(
                "Could not advance Next Reading List version.",
                failureReason: FailureReason::PersistenceWriteFailed
            );
        }
    }

    private function assertTransaction(): void
    {
        if ($this->connection->isTransactionActive() !== true) {
            throw new TransactionException(
                "Next Reading mutation requires an active transaction.",
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
        $date = DateTimeImmutable::createFromFormat("!" . self::DATE_FORMAT, $value, new DateTimeZone("UTC"));
        if (!$date instanceof DateTimeImmutable) {
            throw new PersistenceException("Invalid stored Next Reading timestamp.");
        }
        return $date;
    }
}
