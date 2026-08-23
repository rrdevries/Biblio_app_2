<?php

declare(strict_types=1);

namespace Biblio\Core\NextReading;

use Biblio\Core\Identity\UserId;
use DateTimeImmutable;

interface WritableNextReadingRepository
{
    public function findForUser(UserId $userId, ?int $limit = null): NextReadingList;
    public function lockForUser(UserId $userId, DateTimeImmutable $now): NextReadingList;
    public function append(
        UserId $userId,
        NextReadingEntry $entry,
        NextReadingListVersion $expectedVersion,
        NextReadingListVersion $nextVersion,
        DateTimeImmutable $updatedAt
    ): void;
    /** @param list<NextReadingEntry> $entries */
    public function replaceEntries(
        UserId $userId,
        array $entries,
        NextReadingListVersion $expectedVersion,
        NextReadingListVersion $nextVersion,
        DateTimeImmutable $updatedAt
    ): void;
}
