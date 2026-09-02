<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\NextReading\{NextReadingClock,NextReadingList,NextReadingUndoToken,NextReadingUndoUnavailable,WritableNextReadingRepository};

final readonly class UndoNextReadingRemovalService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WritableNextReadingRepository $repository,
        private NextReadingClock $clock,
        private TransactionManager $transactions
    ) {
    }

    public function undo(NextReadingUndoToken $token): NextReadingList
    {
        $actorId = $this->authenticatedUser->requireUserId();
        return $this->transactions->run(function () use ($actorId, $token): NextReadingList {
            $current = $this->repository->lockForUser($actorId, $this->clock->now());
            $now = $this->clock->now();
            $undo = $this->repository->takeUndo(
                $actorId,
                hash("sha256", $token->value()),
                $now
            );
            if ($undo === null) {
                throw new NextReadingUndoUnavailable();
            }
            $next = $current->restored(
                $undo->entry(),
                $undo->previousEntryId(),
                $undo->nextEntryId(),
                $undo->originalPosition()
            );
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
