<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Notes;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Notes\PrivateNotePage;
use Biblio\Core\Notes\PrivateNotePageRequest;
use Biblio\Core\Notes\PrivateNoteRepository;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundRepository;

final readonly class ListPrivateNotesForReadingRoundService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private PrivateNoteRepository $notes,
        private ReadingRoundRepository $rounds
    ) {
    }

    public function list(ReadingRoundId $roundId, ?PrivateNotePageRequest $page = null): PrivateNotePage
    {
        $actorId = $this->authenticatedUser->requireUserId();

        if ($this->rounds->findForUser($roundId, $actorId) === null) {
            return new PrivateNotePage([], false);
        }

        return $this->notes->findPageForUserAndReadingRound(
            $actorId,
            $roundId,
            $page ?? new PrivateNotePageRequest()
        );
    }
}
