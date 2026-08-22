<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Notes;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Notes\PrivateNote;
use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Notes\PrivateNoteRepository;

final readonly class GetPrivateNoteService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private PrivateNoteRepository $notes
    ) {
    }

    public function get(PrivateNoteId $id): ?PrivateNote
    {
        return $this->notes->findForUser(
            $id,
            $this->authenticatedUser->requireUserId()
        );
    }
}
