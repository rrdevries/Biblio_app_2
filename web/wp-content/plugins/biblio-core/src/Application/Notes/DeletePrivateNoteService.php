<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Notes;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Notes\PrivateNoteNotAvailable;
use Biblio\Core\Notes\PrivateNoteStale;
use Biblio\Core\Notes\PrivateNoteVersion;
use Biblio\Core\Notes\WritablePrivateNoteRepository;

final readonly class DeletePrivateNoteService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WritablePrivateNoteRepository $notes,
        private TransactionManager $transactions
    ) {
    }

    public function delete(PrivateNoteId $id, PrivateNoteVersion $expectedVersion): void
    {
        $actorId = $this->authenticatedUser->requireUserId();

        $this->transactions->run(function () use ($actorId, $id, $expectedVersion): void {
            $current = $this->notes->findForUserForUpdate($id, $actorId);

            if ($current === null) {
                throw new PrivateNoteNotAvailable();
            }

            if (!$current->version()->equals($expectedVersion)) {
                throw new PrivateNoteStale($current);
            }

            if (!$this->notes->deleteIfVersionMatches($actorId, $id, $expectedVersion)) {
                throw new PrivateNoteStale($current);
            }
        });
    }
}
