<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\NextReading\{NextReadingClock,NextReadingEntryId,NextReadingListStale,NextReadingListVersion,NextReadingUndoTokenGenerator,WritableNextReadingRepository};
use DateInterval;

final readonly class RemoveNextReadingEntryService
{
    public const UNDO_TTL_SECONDS = 30;

    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WritableNextReadingRepository $repository,
        private NextReadingClock $clock,
        private NextReadingUndoTokenGenerator $tokens,
        private TransactionManager $transactions
    ) {
    }

    public function remove(
        NextReadingEntryId $entryId,
        NextReadingListVersion $expected
    ): NextReadingRemoval
    {
        $actorId = $this->authenticatedUser->requireUserId();
        return $this->transactions->run(function () use ($actorId, $entryId, $expected): NextReadingRemoval {
            $current = $this->repository->lockForUser($actorId, $this->clock->now());
            $now = $this->clock->now();
            if (!$current->version()->equals($expected)) {
                throw new NextReadingListStale($current);
            }
            $entry = $current->find($entryId);
            if ($entry === null) {
                throw new \Biblio\Core\NextReading\NextReadingEntryNotAvailable();
            }
            $entries = $current->entries();
            $offset = $entry->position()->value() - 1;
            $previous = $offset > 0 ? $entries[$offset - 1]->id() : null;
            $nextAnchor = $offset + 1 < count($entries) ? $entries[$offset + 1]->id() : null;
            $next = $current->without($entryId);
            $token = $this->tokens->next();
            $expiresAt = $now->add(new DateInterval("PT" . self::UNDO_TTL_SECONDS . "S"));
            $this->repository->replaceEntries(
                $actorId,
                $next->entries(),
                $current->version(),
                $next->version(),
                $now
            );
            $this->repository->storeUndo(
                $actorId,
                hash("sha256", $token->value()),
                $entry,
                $previous,
                $nextAnchor,
                $now,
                $expiresAt
            );
            return new NextReadingRemoval($next, $token, $expiresAt);
        });
    }
}
