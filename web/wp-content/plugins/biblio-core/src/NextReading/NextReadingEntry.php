<?php

declare(strict_types=1);

namespace Biblio\Core\NextReading;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;

final readonly class NextReadingEntry
{
    public function __construct(
        private NextReadingEntryId $id,
        private UserId $userId,
        private NextReadingTarget $target,
        private NextReadingPosition $position,
        private DateTimeImmutable $createdAt
    ) {
        PersistedDateTimeConstraints::assertSupported($createdAt, "Next Reading creation time");
    }

    public function id(): NextReadingEntryId { return $this->id; }
    public function userId(): UserId { return $this->userId; }
    public function target(): NextReadingTarget { return $this->target; }
    public function position(): NextReadingPosition { return $this->position; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }

    public function atPosition(NextReadingPosition $position): self
    {
        return new self($this->id, $this->userId, $this->target, $position, $this->createdAt);
    }
}
