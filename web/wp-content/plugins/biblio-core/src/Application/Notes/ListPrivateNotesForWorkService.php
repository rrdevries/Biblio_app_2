<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Notes;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Notes\PrivateNotePage;
use Biblio\Core\Notes\PrivateNotePageRequest;
use Biblio\Core\Notes\PrivateNoteRepository;

final readonly class ListPrivateNotesForWorkService
{
    public function __construct(private AuthenticatedUser $authenticatedUser, private PrivateNoteRepository $notes) {}

    public function list(WorkId $workId, ?PrivateNotePageRequest $page = null): PrivateNotePage
    {
        return $this->notes->findPageForUserAndWork(
            $this->authenticatedUser->requireUserId(),
            $workId,
            $page ?? new PrivateNotePageRequest()
        );
    }
}
