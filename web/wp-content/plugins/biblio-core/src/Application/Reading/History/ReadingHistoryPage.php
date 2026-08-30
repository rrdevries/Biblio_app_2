<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading\History;

final readonly class ReadingHistoryPage
{
    /** @param list<ReadingHistoryEntry> $entries */
    public function __construct(
        private array $entries,
        private ?ReadingHistoryCursor $nextCursor
    ) {
    }

    /** @return list<ReadingHistoryEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function nextCursor(): ?ReadingHistoryCursor
    {
        return $this->nextCursor;
    }
}
