<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Identity\UserId;
use Biblio\Core\NextReading\{NextReadingClock,NextReadingEntry,NextReadingEntryIdCollision,NextReadingEntryIdCollisionExhausted,NextReadingEntryIdGenerator,NextReadingList,NextReadingPosition,NextReadingTarget,NextReadingTargetDuplicate,WritableNextReadingRepository};

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

    public function append(UserId $actorId, NextReadingTarget $target): NextReadingList
    {
        return $this->transactions->run(function () use ($actorId, $target): NextReadingList {
            $now = $this->clock->now();
            $current = $this->repository->lockForUser($actorId, $now);
            if ($current->containsTarget($target)) {
                throw new NextReadingTargetDuplicate();
            }

            for ($attempt = 1; $attempt <= self::MAX_ID_ATTEMPTS; $attempt++) {
                $entry = new NextReadingEntry(
                    $this->ids->next(),
                    $actorId,
                    $target,
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
