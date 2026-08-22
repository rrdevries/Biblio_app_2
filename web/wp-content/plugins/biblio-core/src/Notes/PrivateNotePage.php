<?php

declare(strict_types=1);

namespace Biblio\Core\Notes;

final readonly class PrivateNotePage
{
    /** @param list<PrivateNote> $notes */
    public function __construct(private array $notes, private bool $hasMore)
    {
    }

    /** @return list<PrivateNote> */
    public function notes(): array { return $this->notes; }
    public function hasMore(): bool { return $this->hasMore; }
}
