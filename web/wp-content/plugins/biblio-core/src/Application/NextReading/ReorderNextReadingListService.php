<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\NextReading\{NextReadingClock,NextReadingEntryId,NextReadingList,NextReadingListStale,NextReadingListVersion,WritableNextReadingRepository};

final readonly class ReorderNextReadingListService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WritableNextReadingRepository $repository,
        private NextReadingClock $clock,
        private TransactionManager $transactions
    ) {
    }

    /** @param list<NextReadingEntryId> $orderedEntryIds */
    public function reorder(NextReadingListVersion $expected, array $orderedEntryIds): NextReadingList
    {
        $actorId = $this->authenticatedUser->requireUserId();
        return $this->transactions->run(function () use ($actorId, $expected, $orderedEntryIds): NextReadingList {
            $current = $this->repository->lockForUser($actorId, $this->clock->now());
            try {
                $next = $current->reordered($orderedEntryIds);
            } catch (ValidationException $exception) {
                if (!$current->version()->equals($expected)) {
                    throw new NextReadingListStale($current);
                }
                throw $exception;
            }
            if ($next === $current) {
                $this->repository->discardProvisionedEmptyState($actorId);
                return $current;
            }
            if (!$current->version()->equals($expected)) {
                throw new NextReadingListStale($current);
            }
            $now = $this->clock->now();
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
