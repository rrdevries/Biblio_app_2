<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Notes;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Notes\PrivateNote;
use Biblio\Core\Notes\PrivateNoteClock;
use Biblio\Core\Notes\PrivateNoteContentPolicy;
use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Notes\PrivateNoteNotAvailable;
use Biblio\Core\Notes\PrivateNoteStale;
use Biblio\Core\Notes\PrivateNoteVersion;
use Biblio\Core\Notes\WritablePrivateNoteRepository;

final readonly class UpdatePrivateNoteContentService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WritablePrivateNoteRepository $notes,
        private PrivateNoteContentPolicy $contentPolicy,
        private PrivateNoteClock $clock,
        private TransactionManager $transactions
    ) {
    }

    public function update(
        PrivateNoteId $id,
        PrivateNoteVersion $expectedVersion,
        string $content
    ): PrivateNote {
        $actorId = $this->authenticatedUser->requireUserId();
        $safeContent = $this->contentPolicy->sanitize($content);

        return $this->transactions->run(function () use (
            $actorId,
            $id,
            $expectedVersion,
            $safeContent
        ): PrivateNote {
            $current = $this->notes->findForUserForUpdate($id, $actorId);

            if ($current === null) {
                throw new PrivateNoteNotAvailable();
            }

            if ($current->content()->equals($safeContent)) {
                return $current;
            }

            if (!$current->version()->equals($expectedVersion)) {
                throw new PrivateNoteStale($current);
            }

            $replacement = $current->replaceContent(
                $safeContent,
                $this->clock->now()
            );

            if (!$this->notes->replaceIfVersionMatches(
                $actorId,
                $replacement,
                $expectedVersion
            )) {
                throw new PrivateNoteStale(
                    $this->notes->findForUserForUpdate($id, $actorId) ?? $current
                );
            }

            return $replacement;
        });
    }
}
