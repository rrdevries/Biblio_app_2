<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\NextReading\{NextReadingClock,NextReadingEntryId,NextReadingList,NextReadingListStale,NextReadingListVersion,WritableNextReadingRepository};

final readonly class RemoveNextReadingEntryService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WritableNextReadingRepository $repository,
        private NextReadingClock $clock,
        private TransactionManager $transactions
    ) {
    }

    public function remove(NextReadingEntryId $entryId, NextReadingListVersion $expected): NextReadingList
    {
        $actorId = $this->authenticatedUser->requireUserId();
        return $this->transactions->run(function () use ($actorId, $entryId, $expected): NextReadingList {
            $now = $this->clock->now();
            $current = $this->repository->lockForUser($actorId, $now);
            if (!$current->version()->equals($expected)) {
                throw new NextReadingListStale($current);
            }
            $next = $current->without($entryId);
            $this->repository->replaceEntries(
                $actorId,
                $next->entries(),
                $current->version(),
                $next->version(),
                $now
            );
            return $next;
        });
    }
}
