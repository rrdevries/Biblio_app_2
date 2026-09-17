<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Assessments;

use Biblio\Core\Application\Migration\Runner\TypedMigrationPlan;
use Biblio\Core\Assessments\ReviewContent;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\IdentifierConstraints;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;
use DateTimeZone;

final readonly class HistoricalWrittenReviewPlan implements TypedMigrationPlan
{
    public function __construct(
        private UserId $targetUserId,
        private string $workSourceId,
        private ReviewContent $content,
        private ?DateTimeImmutable $assessedAt,
        private ?string $readingRoundSourceId = null
    ) {
        IdentifierConstraints::assertValid($this->workSourceId, "Review Work source ID");
        if ($this->readingRoundSourceId !== null) {
            IdentifierConstraints::assertValid(
                $this->readingRoundSourceId,
                "Review Reading Round source ID"
            );
        }
        if ($this->assessedAt !== null) {
            try {
                PersistedDateTimeConstraints::assertSupported(
                    $this->assessedAt,
                    "Review assessment time"
                );
            } catch (ValidationException $failure) {
                throw new AssessmentMigrationFailure(
                    AssessmentMigrationReason::InvalidHistoricalTimestamp,
                    "Historical WrittenReview plan has invalid assessment time.",
                    $failure
                );
            }
        }
    }

    public function targetUserId(): UserId { return $this->targetUserId; }
    public function workSourceId(): string { return $this->workSourceId; }
    public function content(): ReviewContent { return $this->content; }
    public function assessedAt(): ?DateTimeImmutable { return $this->assessedAt; }
    public function readingRoundSourceId(): ?string { return $this->readingRoundSourceId; }

    public function canonicalPayload(): array
    {
        return [
            "target_kind" => "written_review",
            "target_user_id" => $this->targetUserId->value(),
            "work_source_id" => $this->workSourceId,
            "reading_round_source_id" => $this->readingRoundSourceId,
            "content" => $this->content->value(),
            "assessed_at" => $this->assessedAt === null
                ? null
                : self::instant($this->assessedAt),
            "visibility" => "private",
            "publication" => null,
            "version" => 1,
        ];
    }

    private static function instant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone("UTC"))
            ->format("Y-m-d\\TH:i:s.u\\Z");
    }
}
