<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Notes;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Catalog\WorkRepository;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Notes\PrivateNote;
use Biblio\Core\Notes\PrivateNoteClock;
use Biblio\Core\Notes\PrivateNoteContent;
use Biblio\Core\Notes\PrivateNoteContentPolicy;
use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Notes\PrivateNoteReadingRoundUnavailable;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundRepository;

final readonly class CreatePrivateNoteService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WorkRepository $works,
        private ReadingRoundRepository $rounds,
        private PrivateNoteContentPolicy $contentPolicy,
        private PrivateNoteCreation $creation,
        private PrivateNoteClock $clock,
        private TransactionManager $transactions
    ) {
    }

    public function createForWork(WorkId $workId, string $content): PrivateNote
    {
        $actorId = $this->authenticatedUser->requireUserId();
        $safeContent = $this->contentPolicy->sanitize($content);

        return $this->transactions->run(function () use (
            $actorId,
            $workId,
            $safeContent
        ): PrivateNote {
            if ($this->works->find($workId) === null) {
                throw new ValidationException("Work does not exist.");
            }

            return $this->create(
                $actorId,
                $workId,
                null,
                $safeContent
            );
        });
    }

    public function createForReadingRound(
        ReadingRoundId $readingRoundId,
        string $content
    ): PrivateNote {
        $actorId = $this->authenticatedUser->requireUserId();
        $safeContent = $this->contentPolicy->sanitize($content);

        return $this->transactions->run(function () use (
            $actorId,
            $readingRoundId,
            $safeContent
        ): PrivateNote {
            $round = $this->rounds->findForUserForUpdate(
                $readingRoundId,
                $actorId
            );

            if ($round === null) {
                throw new PrivateNoteReadingRoundUnavailable();
            }

            return $this->create(
                $actorId,
                $round->workId(),
                $round->id(),
                $safeContent
            );
        });
    }

    private function create(
        \Biblio\Core\Identity\UserId $actorId,
        WorkId $workId,
        ?ReadingRoundId $roundId,
        PrivateNoteContent $content
    ): PrivateNote {
        $createdAt = $this->clock->now();

        return $this->creation->create(
            $actorId,
            static fn (PrivateNoteId $id): PrivateNote => PrivateNote::create(
                $id,
                $actorId,
                $workId,
                $roundId,
                $content,
                $createdAt
            )
        );
    }
}
