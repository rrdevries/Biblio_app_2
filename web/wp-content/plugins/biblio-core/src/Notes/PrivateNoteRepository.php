<?php

declare(strict_types=1);

namespace Biblio\Core\Notes;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\ReadingRoundId;

interface PrivateNoteRepository
{
    public function findForUser(PrivateNoteId $id, UserId $userId): ?PrivateNote;
    public function findForUserForUpdate(PrivateNoteId $id, UserId $userId): ?PrivateNote;
    public function findPageForUserAndWork(UserId $userId, WorkId $workId, PrivateNotePageRequest $page): PrivateNotePage;
    public function findPageForUserAndReadingRound(UserId $userId, ReadingRoundId $roundId, PrivateNotePageRequest $page): PrivateNotePage;
    public function findPageForUser(UserId $userId, PrivateNotePageRequest $page): PrivateNotePage;
}
