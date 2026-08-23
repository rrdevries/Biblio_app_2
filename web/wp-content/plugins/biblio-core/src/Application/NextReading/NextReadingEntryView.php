<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\NextReading\{NextReadingEntryId,NextReadingTargetType};
use DateTimeImmutable;

final readonly class NextReadingEntryView
{
    public function __construct(
        private NextReadingEntryId $id,
        private string $workId,
        private string $workTitle,
        private NextReadingTargetType $targetType,
        private ?string $sourceIdSnapshot,
        private ?string $sourceLibraryIdSnapshot,
        private NextReadingSourceStatus $sourceStatus,
        private int $position,
        private DateTimeImmutable $createdAt
    ) {
    }

    public function id(): NextReadingEntryId { return $this->id; }
    public function workId(): string { return $this->workId; }
    public function workTitle(): string { return $this->workTitle; }
    public function targetType(): NextReadingTargetType { return $this->targetType; }
    public function sourceIdSnapshot(): ?string { return $this->sourceIdSnapshot; }
    public function sourceLibraryIdSnapshot(): ?string { return $this->sourceLibraryIdSnapshot; }
    public function sourceStatus(): NextReadingSourceStatus { return $this->sourceStatus; }
    public function position(): int { return $this->position; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
}
