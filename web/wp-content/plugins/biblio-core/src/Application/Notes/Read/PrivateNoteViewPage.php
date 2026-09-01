<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Notes\Read;

final readonly class PrivateNoteViewPage
{
    /** @param list<PrivateNoteView> $notes */
    public function __construct(
        private array $notes,
        private ?PrivateNoteViewCursor $nextCursor
    ) {
    }

    /** @return list<PrivateNoteView> */
    public function notes(): array
    {
        return $this->notes;
    }

    public function nextCursor(): ?PrivateNoteViewCursor
    {
        return $this->nextCursor;
    }
}
