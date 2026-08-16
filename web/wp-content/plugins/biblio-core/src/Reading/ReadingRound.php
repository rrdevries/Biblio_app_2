<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use DateTimeImmutable;

final readonly class ReadingRound
{
    public function __construct(
        private ReadingRoundId $id,
        private UserId $userId,
        private WorkId $workId,
        private ReadingSource $source,
        private ReadingRoundStatus $status,
        private DateTimeImmutable $startedAt
    ) {
    }

    public static function active(
        ReadingRoundId $id,
        UserId $userId,
        WorkId $workId,
        ReadingSource $source,
        DateTimeImmutable $startedAt
    ): self {
        return new self(
            $id,
            $userId,
            $workId,
            $source,
            ReadingRoundStatus::Active,
            $startedAt
        );
    }

    public function id(): ReadingRoundId
    {
        return $this->id;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function workId(): WorkId
    {
        return $this->workId;
    }

    public function source(): ReadingSource
    {
        return $this->source;
    }

    public function status(): ReadingRoundStatus
    {
        return $this->status;
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }
}
