<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicAuthorWorkSearchCursor
{
    public function __construct(
        private string $authorContextId,
        private BibliographicAuthorWorkSearchLane $lane,
        private int $nextOffset
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $authorContextId) !== 1) {
            throw new ValidationException("Invalid Works-by-Author cursor context.");
        }
        if ($nextOffset < 0 || $nextOffset > 1000000
            || ($lane === BibliographicAuthorWorkSearchLane::Local && $nextOffset === 0)) {
            throw new ValidationException("Invalid Works-by-Author cursor offset.");
        }
    }

    public static function forAuthor(
        BibliographicAuthorReference $author,
        BibliographicAuthorWorkSearchLane $lane,
        int $nextOffset
    ): self {
        return new self($author->cursorContextId(), $lane, $nextOffset);
    }

    public function authorContextId(): string { return $this->authorContextId; }
    public function lane(): BibliographicAuthorWorkSearchLane { return $this->lane; }
    public function nextOffset(): int { return $this->nextOffset; }
}
