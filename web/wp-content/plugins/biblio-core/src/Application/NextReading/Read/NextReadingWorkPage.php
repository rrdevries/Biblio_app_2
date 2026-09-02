<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading\Read;

final readonly class NextReadingWorkPage
{
    /** @param list<NextReadingWorkView> $works */
    public function __construct(
        private array $works,
        private ?NextReadingWorkCursor $nextCursor
    ) {
    }

    /** @return list<NextReadingWorkView> */
    public function works(): array { return $this->works; }
    public function nextCursor(): ?NextReadingWorkCursor { return $this->nextCursor; }
}
