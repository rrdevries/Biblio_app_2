<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\NextReading\NextReadingEntryId;
use DateTimeImmutable;

final readonly class NextReadingEntryView
{
    public function __construct(
        private NextReadingEntryId $id,
        private string $workId,
        private string $workTitle,
        private PreferredReadingSourceView $preferredSource,
        private int $position,
        private DateTimeImmutable $createdAt
    ) {
    }

    public function id(): NextReadingEntryId { return $this->id; }
    public function workId(): string { return $this->workId; }
    public function workTitle(): string { return $this->workTitle; }
    public function preferredSource(): PreferredReadingSourceView { return $this->preferredSource; }
    public function position(): int { return $this->position; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
}
