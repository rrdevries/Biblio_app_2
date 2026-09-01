<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Notes\Read;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Notes\RenderPrivateNoteContentService;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Notes\PrivateNote;
use Biblio\Core\Notes\PrivateNotePageRequest;
use Biblio\Core\Notes\PrivateNoteRepository;
use LogicException;

final readonly class GetMyPrivateNotesForWorkService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private PrivateNoteRepository $notes,
        private RenderPrivateNoteContentService $rendering
    ) {
    }

    public function forWork(
        WorkId $workId,
        ?PrivateNotePageRequest $page = null
    ): PrivateNoteViewPage {
        $sourcePage = $this->notes->findPageForUserAndWork(
            $this->authenticatedUser->requireUserId(),
            $workId,
            $page ?? new PrivateNotePageRequest()
        );
        $sourceNotes = $sourcePage->notes();
        $views = array_map($this->view(...), $sourceNotes);
        $last = $sourceNotes === [] ? null : $sourceNotes[array_key_last($sourceNotes)];

        if ($sourcePage->hasMore() && !$last instanceof PrivateNote) {
            throw new LogicException(
                "A Private Note page with a continuation must contain a Note."
            );
        }

        return new PrivateNoteViewPage(
            $views,
            $sourcePage->hasMore()
                ? new PrivateNoteViewCursor($last->updatedAt(), $last->id())
                : null
        );
    }

    private function view(PrivateNote $note): PrivateNoteView
    {
        return PrivateNoteView::fromPrivateNote($note, $this->rendering);
    }
}
