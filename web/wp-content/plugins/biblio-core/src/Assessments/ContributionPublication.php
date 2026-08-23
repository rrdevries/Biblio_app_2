<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;
final readonly class ContributionPublication
{
    public function __construct(private PublicationId $id, private LibraryId $libraryId, private ?RatingId $ratingId, private ?ReviewId $reviewId, private AuthorStatus $authorStatus, private ModerationStatus $moderationStatus, private ?ModerationReason $moderationReason, private ?UserId $moderatorUserId, private ?DateTimeImmutable $moderatedAt, private DateTimeImmutable $publishedAt, private DateTimeImmutable $updatedAt, private PublicationVersion $version)
    {
        if (($ratingId === null) === ($reviewId === null)) throw new ValidationException("Publication must reference exactly one contribution.");
        $hasModeration = $moderationReason !== null && $moderatorUserId !== null && $moderatedAt !== null;
        if (($moderationStatus === ModerationStatus::Visible && $hasModeration) || ($moderationStatus !== ModerationStatus::Visible && !$hasModeration)) throw new ValidationException("Publication moderation metadata is inconsistent.");
        PersistedDateTimeConstraints::assertSupported($publishedAt, "Publication time"); PersistedDateTimeConstraints::assertSupported($updatedAt, "Publication update time"); if ($updatedAt < $publishedAt) throw new ValidationException("Publication update time cannot precede publication time.");
    }
    public static function forRating(PublicationId $id, LibraryId $library, RatingId $rating, DateTimeImmutable $now): self { return new self($id, $library, $rating, null, AuthorStatus::Active, ModerationStatus::Visible, null, null, null, $now, $now, PublicationVersion::initial()); }
    public static function forReview(PublicationId $id, LibraryId $library, ReviewId $review, DateTimeImmutable $now): self { return new self($id, $library, null, $review, AuthorStatus::Active, ModerationStatus::Visible, null, null, null, $now, $now, PublicationVersion::initial()); }
    public function withdraw(DateTimeImmutable $now): self { return $this->replace(AuthorStatus::Withdrawn, $this->moderationStatus, $this->moderationReason, $this->moderatorUserId, $this->moderatedAt, $this->publishedAt, $now); }
    public function republish(DateTimeImmutable $now): self { if ($this->moderationStatus === ModerationStatus::Removed) throw new ValidationException("Removed publication is terminal in this Library."); return $this->replace(AuthorStatus::Active, $this->moderationStatus, $this->moderationReason, $this->moderatorUserId, $this->moderatedAt, $now, $now); }
    public function moderate(ModerationStatus $status, ModerationReason $reason, UserId $moderator, DateTimeImmutable $now): self { if ($status === ModerationStatus::Visible) throw new ValidationException("Use restore for visible moderation state."); if ($this->moderationStatus === ModerationStatus::Removed) throw new ValidationException("Removed publication is terminal."); return $this->replace($this->authorStatus, $status, $reason, $moderator, $now, $this->publishedAt, $now); }
    public function restore(DateTimeImmutable $now): self { if ($this->moderationStatus !== ModerationStatus::Hidden) throw new ValidationException("Only hidden publication can be restored."); return $this->replace($this->authorStatus, ModerationStatus::Visible, null, null, null, $this->publishedAt, $now); }
    private function replace(AuthorStatus $author, ModerationStatus $moderation, ?ModerationReason $reason, ?UserId $moderator, ?DateTimeImmutable $moderated, DateTimeImmutable $published, DateTimeImmutable $updated): self { return new self($this->id, $this->libraryId, $this->ratingId, $this->reviewId, $author, $moderation, $reason, $moderator, $moderated, $published, $updated, $this->version->next()); }
    public function id(): PublicationId { return $this->id; } public function libraryId(): LibraryId { return $this->libraryId; } public function ratingId(): ?RatingId { return $this->ratingId; } public function reviewId(): ?ReviewId { return $this->reviewId; } public function authorStatus(): AuthorStatus { return $this->authorStatus; } public function moderationStatus(): ModerationStatus { return $this->moderationStatus; } public function moderationReason(): ?ModerationReason { return $this->moderationReason; } public function moderatorUserId(): ?UserId { return $this->moderatorUserId; } public function moderatedAt(): ?DateTimeImmutable { return $this->moderatedAt; } public function publishedAt(): DateTimeImmutable { return $this->publishedAt; } public function updatedAt(): DateTimeImmutable { return $this->updatedAt; } public function version(): PublicationVersion { return $this->version; }
}
