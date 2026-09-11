<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicEditionSearchCursor
{
    public function __construct(
        private string $workContextId,
        private BibliographicEditionSearchLane $lane,
        private int $nextOffset
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $workContextId) !== 1) {
            throw new ValidationException("Invalid Edition cursor Work context.");
        }
        if ($nextOffset < 0 || $nextOffset > 1000000
            || ($lane === BibliographicEditionSearchLane::Local && $nextOffset === 0)) {
            throw new ValidationException("Invalid Edition cursor offset.");
        }
    }

    public static function forWork(
        BibliographicWorkReference $work,
        BibliographicEditionSearchLane $lane,
        int $nextOffset
    ): self {
        return new self($work->cursorContextId(), $lane, $nextOffset);
    }

    public function workContextId(): string { return $this->workContextId; }
    public function lane(): BibliographicEditionSearchLane { return $this->lane; }
    public function nextOffset(): int { return $this->nextOffset; }
}
