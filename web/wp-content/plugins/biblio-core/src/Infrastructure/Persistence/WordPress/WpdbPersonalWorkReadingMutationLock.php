<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Exception\TransactionException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\PersonalWorkReadingMutationLock;
use DateTimeImmutable;
use DateTimeZone;
use wpdb;

final readonly class WpdbPersonalWorkReadingMutationLock implements
    PersonalWorkReadingMutationLock
{
    private WpdbTransactionConnection $connection;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
        $this->connection = new WpdbTransactionConnection($database);
    }

    public function acquire(UserId $userId, WorkId $workId): void
    {
        if ($this->connection->isTransactionActive() !== true) {
            throw new TransactionException(
                "Personal Work reading lock requires an active transaction.",
                FailureReason::TransactionBeginFailed
            );
        }

        $table = $this->tables->personalWorkReadingLocks();
        $now = (new DateTimeImmutable("now", new DateTimeZone("UTC")))
            ->format("Y-m-d H:i:s.u");
        $inserted = $this->database->query($this->database->prepare(
            "INSERT INTO `{$table}` (user_id,work_id,created_at) VALUES (%s,%s,%s) "
                . "ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)",
            $userId->value(),
            $workId->value(),
            $now
        ));
        if ($inserted === false) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not acquire Personal Work reading lock.",
                $this->database->last_error
            );
        }

        $locked = $this->database->get_var($this->database->prepare(
            "SELECT work_id FROM `{$table}` WHERE user_id=%s AND work_id=%s FOR UPDATE",
            $userId->value(),
            $workId->value()
        ));
        if (!is_string($locked) || $locked !== $workId->value()) {
            throw new TransactionException(
                "Personal Work reading lock could not be confirmed.",
                FailureReason::TransactionBeginFailed
            );
        }
    }
}
