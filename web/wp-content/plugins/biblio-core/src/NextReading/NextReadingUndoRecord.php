<?php

declare(strict_types=1);

namespace Biblio\Core\NextReading;

use DateTimeImmutable;

final readonly class NextReadingUndoRecord
{
    public function __construct(
        private NextReadingEntry $entry,
        private ?NextReadingEntryId $previousEntryId,
        private ?NextReadingEntryId $nextEntryId,
        private int $originalPosition,
        private DateTimeImmutable $expiresAt
    ) {
    }

    public function entry(): NextReadingEntry { return $this->entry; }
    public function previousEntryId(): ?NextReadingEntryId { return $this->previousEntryId; }
    public function nextEntryId(): ?NextReadingEntryId { return $this->nextEntryId; }
    public function originalPosition(): int { return $this->originalPosition; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
}
