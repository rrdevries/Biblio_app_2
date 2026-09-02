<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\NextReading\{NextReadingClock,NextReadingEntryId,NextReadingEntryNotAvailable,NextReadingList,WritableNextReadingRepository};
use Biblio\Core\Reading\ReadingSource;
use DateTimeImmutable;

final readonly class ConsumeNextReadingAfterStartService
{
    public function __construct(
        private WritableNextReadingRepository $repository,
        private NextReadingClock $clock
    ) {
    }

    public function lock(UserId $userId): NextReadingList
    {
        return $this->repository->lockForUser($userId, $this->clock->now());
    }

    public function assertExplicitEntry(
        NextReadingList $list,
        ?NextReadingEntryId $entryId,
        WorkId $workId
    ): void {
        if ($entryId === null) {
            return;
        }
        $entry = $list->find($entryId);
        if ($entry === null || !$entry->workId()->equals($workId)) {
            throw new NextReadingEntryNotAvailable();
        }
    }

    public function consume(
        UserId $userId,
        NextReadingList $lockedList,
        WorkId $workId,
        ReadingSource $actualSource,
        ?NextReadingEntryId $explicitEntryId,
        DateTimeImmutable $now
    ): ?NextReadingList {
        $entryId = $explicitEntryId
            ?? $lockedList->matchingEntryId($workId, $actualSource);
        if ($entryId === null) {
            $this->repository->discardProvisionedEmptyState($userId);
            return null;
        }
        $next = $lockedList->without($entryId);
        $this->repository->replaceEntries(
            $userId,
            $next->entries(),
            $lockedList->version(),
            $next->version(),
            $now
        );
        return $next;
    }
}
