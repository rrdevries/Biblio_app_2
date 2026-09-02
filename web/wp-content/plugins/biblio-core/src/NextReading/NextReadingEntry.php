<?php

declare(strict_types=1);

namespace Biblio\Core\NextReading;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;

final readonly class NextReadingEntry
{
    public function __construct(
        private NextReadingEntryId $id,
        private UserId $userId,
        private WorkId $workId,
        private ?PreferredReadingSource $preferredSource,
        private NextReadingPosition $position,
        private DateTimeImmutable $createdAt
    ) {
        PersistedDateTimeConstraints::assertSupported($createdAt, "Next Reading creation time");
    }

    public function id(): NextReadingEntryId { return $this->id; }
    public function userId(): UserId { return $this->userId; }
    public function workId(): WorkId { return $this->workId; }
    public function preferredSource(): ?PreferredReadingSource { return $this->preferredSource; }
    public function position(): NextReadingPosition { return $this->position; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }

    public function atPosition(NextReadingPosition $position): self
    {
        return new self(
            $this->id,
            $this->userId,
            $this->workId,
            $this->preferredSource,
            $position,
            $this->createdAt
        );
    }

    public function withPreferredSource(?PreferredReadingSource $source): self
    {
        return new self(
            $this->id,
            $this->userId,
            $this->workId,
            $source,
            $this->position,
            $this->createdAt
        );
    }
}
