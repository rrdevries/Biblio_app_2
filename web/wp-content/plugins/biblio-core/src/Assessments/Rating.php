<?php

declare(strict_types=1);

namespace Biblio\Core\Assessments;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;

final readonly class Rating
{
    public function __construct(
        private RatingId $id,
        private UserId $userId,
        private WorkId $workId,
        private ?ReadingRoundId $readingRoundId,
        private RatingValue $value,
        private ?DateTimeImmutable $assessedAt,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private RatingVersion $version
    ) {
        if ($assessedAt !== null) {
            PersistedDateTimeConstraints::assertSupported($assessedAt, "Rating assessment time");
        }
        PersistedDateTimeConstraints::assertSupported($createdAt, "Rating creation time");
        PersistedDateTimeConstraints::assertSupported($updatedAt, "Rating update time");
        if ($assessedAt !== null && $assessedAt > $createdAt) {
            throw new ValidationException(
                "Rating assessment time cannot follow creation time."
            );
        }
        if ($updatedAt < $createdAt) {
            throw new ValidationException("Rating update time cannot precede creation time.");
        }
    }

    public static function create(RatingId $id, UserId $userId, WorkId $workId, ?ReadingRoundId $roundId, RatingValue $value, DateTimeImmutable $now): self
    {
        return new self($id, $userId, $workId, $roundId, $value, $now, $now, $now, RatingVersion::initial());
    }

    public static function historical(RatingId $id, UserId $userId, WorkId $workId, ?ReadingRoundId $roundId, RatingValue $value, ?DateTimeImmutable $assessedAt, DateTimeImmutable $recordedAt): self
    {
        return new self($id, $userId, $workId, $roundId, $value, $assessedAt, $recordedAt, $recordedAt, RatingVersion::initial());
    }

    public function withValue(RatingValue $value, DateTimeImmutable $now): self { return $this->replacement($this->readingRoundId, $value, $now); }
    public function withReadingRound(?ReadingRoundId $roundId, DateTimeImmutable $now): self { return $this->replacement($roundId, $this->value, $now); }
    private function replacement(?ReadingRoundId $roundId, RatingValue $value, DateTimeImmutable $now): self { return new self($this->id, $this->userId, $this->workId, $roundId, $value, $this->assessedAt, $this->createdAt, $now, $this->version->next()); }
    public function id(): RatingId { return $this->id; }
    public function userId(): UserId { return $this->userId; }
    public function workId(): WorkId { return $this->workId; }
    public function readingRoundId(): ?ReadingRoundId { return $this->readingRoundId; }
    public function value(): RatingValue { return $this->value; }
    public function assessedAt(): ?DateTimeImmutable { return $this->assessedAt; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function version(): RatingVersion { return $this->version; }
    public function hasRound(?ReadingRoundId $id): bool { return $this->readingRoundId === null ? $id === null : $id !== null && $this->readingRoundId->equals($id); }
}
