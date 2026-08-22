<?php

declare(strict_types=1);

namespace Biblio\Core\Notes;

use Biblio\Core\Identity\UserId;

interface WritablePrivateNoteRepository extends PrivateNoteRepository
{
    public function addForUser(UserId $authenticatedUserId, PrivateNote $note): void;
    public function replaceIfVersionMatches(UserId $authenticatedUserId, PrivateNote $replacement, PrivateNoteVersion $expectedVersion): bool;
    public function deleteIfVersionMatches(UserId $authenticatedUserId, PrivateNoteId $id, PrivateNoteVersion $expectedVersion): bool;
}
