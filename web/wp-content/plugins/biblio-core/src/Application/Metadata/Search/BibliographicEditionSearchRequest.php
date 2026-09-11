<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicEditionSearchRequest
{
    public function __construct(
        private BibliographicWorkReference $work,
        private ?BibliographicEditionSearchCursor $cursor = null
    ) {
        if ($cursor !== null && $cursor->workContextId() !== $work->cursorContextId()) {
            throw new ValidationException("Edition cursor does not match the selected Work.");
        }
        if ($cursor?->lane() === BibliographicEditionSearchLane::Local
            && $work->workId() === null) {
            throw new ValidationException("External Work cannot use a local Edition cursor.");
        }
    }

    public function work(): BibliographicWorkReference { return $this->work; }
    public function cursor(): ?BibliographicEditionSearchCursor { return $this->cursor; }
}
