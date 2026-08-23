<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;
final readonly class WrittenReview
{
    public function __construct(private ReviewId $id, private UserId $userId, private WorkId $workId, private ?ReadingRoundId $readingRoundId, private ReviewContent $content, private DateTimeImmutable $createdAt, private DateTimeImmutable $updatedAt, private ReviewVersion $version) { PersistedDateTimeConstraints::assertSupported($createdAt, "Review creation time"); PersistedDateTimeConstraints::assertSupported($updatedAt, "Review update time"); if ($updatedAt < $createdAt) throw new ValidationException("Review update time cannot precede creation time."); }
    public static function create(ReviewId $id, UserId $userId, WorkId $workId, ?ReadingRoundId $roundId, ReviewContent $content, DateTimeImmutable $now): self { return new self($id, $userId, $workId, $roundId, $content, $now, $now, ReviewVersion::initial()); }
    public function withContent(ReviewContent $content, DateTimeImmutable $now): self { return $this->replacement($this->readingRoundId, $content, $now); }
    public function withReadingRound(?ReadingRoundId $roundId, DateTimeImmutable $now): self { return $this->replacement($roundId, $this->content, $now); }
    private function replacement(?ReadingRoundId $roundId, ReviewContent $content, DateTimeImmutable $now): self { return new self($this->id, $this->userId, $this->workId, $roundId, $content, $this->createdAt, $now, $this->version->next()); }
    public function id(): ReviewId { return $this->id; } public function userId(): UserId { return $this->userId; } public function workId(): WorkId { return $this->workId; } public function readingRoundId(): ?ReadingRoundId { return $this->readingRoundId; } public function content(): ReviewContent { return $this->content; } public function createdAt(): DateTimeImmutable { return $this->createdAt; } public function updatedAt(): DateTimeImmutable { return $this->updatedAt; } public function version(): ReviewVersion { return $this->version; }
    public function hasRound(?ReadingRoundId $id): bool { return $this->readingRoundId === null ? $id === null : $id !== null && $this->readingRoundId->equals($id); }
}
