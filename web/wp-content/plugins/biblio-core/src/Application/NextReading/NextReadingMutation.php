<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\NextReading\{NextReadingClock,NextReadingEntry,NextReadingEntryIdCollision,NextReadingEntryIdCollisionExhausted,NextReadingEntryIdGenerator,NextReadingList,NextReadingPosition,PreferredReadingSource,WritableNextReadingRepository};

final readonly class NextReadingMutation
{
    private const MAX_ID_ATTEMPTS = 4;

    public function __construct(
        private WritableNextReadingRepository $repository,
        private NextReadingEntryIdGenerator $ids,
        private NextReadingClock $clock,
        private TransactionManager $transactions
    ) {
    }

    public function append(
        UserId $actorId,
        WorkId $workId,
        ?PreferredReadingSource $preferredSource
    ): NextReadingList
    {
        return $this->transactions->run(function () use ($actorId, $workId, $preferredSource): NextReadingList {
            $current = $this->repository->lockForUser($actorId, $this->clock->now());
            $now = $this->clock->now();

            for ($attempt = 1; $attempt <= self::MAX_ID_ATTEMPTS; $attempt++) {
                $entry = new NextReadingEntry(
                    $this->ids->next(),
                    $actorId,
                    $workId,
                    $preferredSource,
                    new NextReadingPosition(count($current->entries()) + 1),
                    $now
                );
                if ($current->find($entry->id()) !== null) {
                    if ($attempt === self::MAX_ID_ATTEMPTS) {
                        throw new NextReadingEntryIdCollisionExhausted();
                    }
                    continue;
                }
                try {
                    $next = $current->append($entry);
                    $this->repository->append(
                        $actorId,
                        $next->entries()[array_key_last($next->entries())],
                        $current->version(),
                        $next->version(),
                        $now
                    );
                    return $next;
                } catch (NextReadingEntryIdCollision) {
                    if ($attempt === self::MAX_ID_ATTEMPTS) {
                        throw new NextReadingEntryIdCollisionExhausted();
                    }
                }
            }

            throw new NextReadingEntryIdCollisionExhausted();
        });
    }
}
