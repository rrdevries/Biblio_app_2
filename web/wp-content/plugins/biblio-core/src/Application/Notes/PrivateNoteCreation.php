<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Notes;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Notes\PrivateNote;
use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Notes\PrivateNoteIdCollision;
use Biblio\Core\Notes\PrivateNoteIdCollisionExhausted;
use Biblio\Core\Notes\PrivateNoteIdGenerator;
use Biblio\Core\Notes\WritablePrivateNoteRepository;

final readonly class PrivateNoteCreation
{
    private const MAX_COLLISION_RETRIES = 3;

    public function __construct(
        private PrivateNoteIdGenerator $ids,
        private WritablePrivateNoteRepository $notes
    ) {
    }

    /** @param callable(PrivateNoteId): PrivateNote $factory */
    public function create(UserId $actorId, callable $factory): PrivateNote
    {
        $retries = 0;

        while (true) {
            $note = $factory($this->ids->next());

            try {
                $this->notes->addForUser($actorId, $note);

                return $note;
            } catch (PrivateNoteIdCollision $collision) {
                if ($retries >= self::MAX_COLLISION_RETRIES) {
                    throw new PrivateNoteIdCollisionExhausted($collision);
                }

                $retries++;
            }
        }
    }
}
