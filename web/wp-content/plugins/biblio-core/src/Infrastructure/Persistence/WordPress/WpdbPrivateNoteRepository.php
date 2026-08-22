<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Exception\TransactionException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Notes\PrivateNote;
use Biblio\Core\Notes\PrivateNoteContentPolicy;
use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Notes\PrivateNoteIdCollision;
use Biblio\Core\Notes\PrivateNotePage;
use Biblio\Core\Notes\PrivateNotePageRequest;
use Biblio\Core\Notes\PrivateNoteVersion;
use Biblio\Core\Notes\WritablePrivateNoteRepository;
use Biblio\Core\Reading\ReadingRoundId;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use wpdb;

final readonly class WpdbPrivateNoteRepository implements WritablePrivateNoteRepository
{
    private const DATABASE_DATE_FORMAT = "Y-m-d H:i:s.u";
    private WpdbTransactionConnection $transactionConnection;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames,
        private PrivateNoteContentPolicy $contentPolicy
    ) {
        $this->transactionConnection = new WpdbTransactionConnection($database);
    }

    public function addForUser(UserId $authenticatedUserId, PrivateNote $note): void
    {
        $this->assertTransactionActive();
        $this->assertOwner($authenticatedUserId, $note);
        $this->assertReadingRoundContext($note);
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $this->database->insert(
                $this->tableNames->privateNotes(),
                [
                    "private_note_id" => $note->id()->value(),
                    "user_id" => $note->userId()->value(),
                    "work_id" => $note->workId()->value(),
                    "reading_round_id" => $note->readingRoundId()?->value(),
                    "note_content" => $note->content()->value(),
                    "created_at" => $this->formatInstant($note->createdAt()),
                    "updated_at" => $this->formatInstant($note->updatedAt()),
                    "note_version" => $note->version()->value(),
                ],
                ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%d"]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result === 1) {
            return;
        }

        $conflict = WpdbErrorTranslator::conflict($this->database->last_error);

        if ($conflict?->constraintName() === "PRIMARY") {
            throw new PrivateNoteIdCollision(
                WpdbErrorTranslator::diagnostic(
                    "Private Note insert",
                    $this->database->last_error
                )
            );
        }

        throw WpdbErrorTranslator::writeFailure(
            "Could not persist Private Note.",
            $this->database->last_error
        );
    }

    public function replaceIfVersionMatches(
        UserId $authenticatedUserId,
        PrivateNote $replacement,
        PrivateNoteVersion $expectedVersion
    ): bool {
        $this->assertTransactionActive();
        $this->assertOwner($authenticatedUserId, $replacement);

        if ($replacement->version()->value() !== $expectedVersion->value() + 1) {
            throw new PersistenceException(
                "Private Note replacement must increment version once.",
                failureReason: FailureReason::PersistenceWriteFailed
            );
        }

        $this->assertReadingRoundContext($replacement);
        $table = $this->tableNames->privateNotes();
        $roundSql = $replacement->readingRoundId() === null
            ? "NULL"
            : $this->database->prepare("%s", $replacement->readingRoundId()->value());
        $result = $this->database->query($this->database->prepare(
            "UPDATE `{$table}` SET reading_round_id = {$roundSql}, "
            . "note_content = %s, updated_at = %s, note_version = %d "
            . "WHERE private_note_id = %s AND user_id = %s "
            . "AND work_id = %s AND note_version = %d",
            $replacement->content()->value(),
            $this->formatInstant($replacement->updatedAt()),
            $replacement->version()->value(),
            $replacement->id()->value(),
            $authenticatedUserId->value(),
            $replacement->workId()->value(),
            $expectedVersion->value()
        ));

        if ($result === false) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not update Private Note.",
                $this->database->last_error
            );
        }

        return $result === 1;
    }

    public function deleteIfVersionMatches(
        UserId $authenticatedUserId,
        PrivateNoteId $id,
        PrivateNoteVersion $expectedVersion
    ): bool {
        $this->assertTransactionActive();
        $table = $this->tableNames->privateNotes();
        $result = $this->database->query($this->database->prepare(
            "DELETE FROM `{$table}` WHERE private_note_id = %s "
            . "AND user_id = %s AND note_version = %d",
            $id->value(),
            $authenticatedUserId->value(),
            $expectedVersion->value()
        ));

        if ($result === false) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not delete Private Note.",
                $this->database->last_error
            );
        }

        return $result === 1;
    }

    public function findForUser(PrivateNoteId $id, UserId $userId): ?PrivateNote
    {
        return $this->findOne($id, $userId, false);
    }

    public function findForUserForUpdate(PrivateNoteId $id, UserId $userId): ?PrivateNote
    {
        $this->assertTransactionActive();

        return $this->findOne($id, $userId, true);
    }

    public function findPageForUserAndWork(
        UserId $userId,
        WorkId $workId,
        PrivateNotePageRequest $page
    ): PrivateNotePage {
        return $this->findPage($userId, "work_id", $workId->value(), $page);
    }

    public function findPageForUserAndReadingRound(
        UserId $userId,
        ReadingRoundId $roundId,
        PrivateNotePageRequest $page
    ): PrivateNotePage {
        return $this->findPage(
            $userId,
            "reading_round_id",
            $roundId->value(),
            $page
        );
    }

    public function findPageForUser(
        UserId $userId,
        PrivateNotePageRequest $page
    ): PrivateNotePage {
        return $this->findPage($userId, null, null, $page);
    }

    private function findOne(
        PrivateNoteId $id,
        UserId $userId,
        bool $forUpdate
    ): ?PrivateNote {
        $table = $this->tableNames->privateNotes();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT {$this->selectColumns()} FROM `{$table}` "
            . "WHERE private_note_id = %s AND user_id = %s"
            . ($forUpdate ? " FOR UPDATE" : ""),
            $id->value(),
            $userId->value()
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    private function findPage(
        UserId $userId,
        ?string $scopeColumn,
        ?string $scopeValue,
        PrivateNotePageRequest $page
    ): PrivateNotePage {
        $table = $this->tableNames->privateNotes();
        $where = "user_id = %s";
        $args = [$userId->value()];

        if ($scopeColumn !== null && $scopeValue !== null) {
            $where .= " AND `{$scopeColumn}` = %s";
            $args[] = $scopeValue;
        }

        if ($page->beforeUpdatedAt() !== null && $page->beforeId() !== null) {
            $where .= " AND (updated_at < %s OR "
                . "(updated_at = %s AND private_note_id < %s))";
            $cursorTime = $this->formatInstant($page->beforeUpdatedAt());
            $args[] = $cursorTime;
            $args[] = $cursorTime;
            $args[] = $page->beforeId()->value();
        }

        $args[] = $page->limit() + 1;
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT {$this->selectColumns()} FROM `{$table}` WHERE {$where} "
            . "ORDER BY updated_at DESC, private_note_id DESC LIMIT %d",
            ...$args
        ));
        $hasMore = count($rows) > $page->limit();

        if ($hasMore) {
            array_pop($rows);
        }

        return new PrivateNotePage(array_map($this->hydrate(...), $rows), $hasMore);
    }

    private function hydrate(object $row): PrivateNote
    {
        try {
            return new PrivateNote(
                new PrivateNoteId((string) $row->private_note_id),
                new UserId((string) $row->user_id),
                new WorkId((string) $row->work_id),
                $row->reading_round_id === null
                    ? null
                    : new ReadingRoundId((string) $row->reading_round_id),
                $this->contentPolicy->sanitize((string) $row->note_content),
                $this->hydrateInstant($row->created_at),
                $this->hydrateInstant($row->updated_at),
                new PrivateNoteVersion((int) $row->note_version)
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Private Note data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    private function assertReadingRoundContext(PrivateNote $note): void
    {
        if ($note->readingRoundId() === null) {
            return;
        }

        $rounds = $this->tableNames->readingRounds();
        $match = $this->database->get_var($this->database->prepare(
            "SELECT reading_round_id FROM `{$rounds}` "
            . "WHERE reading_round_id = %s AND user_id = %s AND work_id = %s",
            $note->readingRoundId()->value(),
            $note->userId()->value(),
            $note->workId()->value()
        ));

        if (!is_string($match)) {
            throw new PersistenceException(
                "Private Note Reading Round context is inconsistent.",
                failureReason: FailureReason::PersistenceWriteFailed
            );
        }
    }

    private function assertOwner(UserId $actorId, PrivateNote $note): void
    {
        if (!$actorId->equals($note->userId())) {
            throw new AuthorizationException(
                "Cannot persist a Private Note for another user."
            );
        }
    }

    private function assertTransactionActive(): void
    {
        if ($this->transactionConnection->isTransactionActive() !== true) {
            throw new TransactionException(
                "Private Note mutation requires an active transaction.",
                FailureReason::TransactionBeginFailed
            );
        }
    }

    private function selectColumns(): string
    {
        return "private_note_id, user_id, work_id, reading_round_id, "
            . "note_content, created_at, updated_at, note_version";
    }

    private function formatInstant(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone("UTC"))
            ->format(self::DATABASE_DATE_FORMAT);
    }

    private function hydrateInstant(mixed $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            "!" . self::DATABASE_DATE_FORMAT,
            (string) $value,
            new DateTimeZone("UTC")
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && ($errors["warning_count"] > 0 || $errors["error_count"] > 0))
        ) {
            throw new PersistenceException(
                "Stored Private Note instant is invalid.",
                failureReason: FailureReason::PersistenceReadFailed
            );
        }

        return $date;
    }
}
