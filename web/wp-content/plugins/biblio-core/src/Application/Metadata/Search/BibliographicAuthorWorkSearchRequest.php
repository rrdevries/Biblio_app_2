<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicAuthorWorkSearchRequest
{
    public function __construct(
        private BibliographicAuthorReference $author,
        private ?BibliographicAuthorWorkSearchCursor $cursor = null
    ) {
        if ($cursor !== null && $cursor->authorContextId() !== $author->cursorContextId()) {
            throw new ValidationException("Works-by-Author cursor does not match the selected Author.");
        }
        if ($cursor?->lane() === BibliographicAuthorWorkSearchLane::Local
            && $author->authorId() === null) {
            throw new ValidationException("External Author cannot use a local Works cursor.");
        }
    }

    public function author(): BibliographicAuthorReference { return $this->author; }
    public function cursor(): ?BibliographicAuthorWorkSearchCursor { return $this->cursor; }
}
