<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Assessments;

use Biblio\Core\Application\Migration\Runner\TypedMigrationPlan;
use Biblio\Core\Assessments\RatingValue;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\IdentifierConstraints;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;
use DateTimeZone;

final readonly class HistoricalRatingPlan implements TypedMigrationPlan
{
    public function __construct(
        private UserId $targetUserId,
        private string $workSourceId,
        private RatingValue $value,
        private ?DateTimeImmutable $assessedAt,
        private ?string $readingRoundSourceId = null
    ) {
        IdentifierConstraints::assertValid($this->workSourceId, "Rating Work source ID");
        if ($this->readingRoundSourceId !== null) {
            IdentifierConstraints::assertValid(
                $this->readingRoundSourceId,
                "Rating Reading Round source ID"
            );
        }
        if ($this->assessedAt !== null) {
            try {
                PersistedDateTimeConstraints::assertSupported(
                    $this->assessedAt,
                    "Rating assessment time"
                );
            } catch (ValidationException $failure) {
                throw new AssessmentMigrationFailure(
                    AssessmentMigrationReason::InvalidHistoricalTimestamp,
                    "Historical Rating plan has invalid assessment time.",
                    $failure
                );
            }
        }
    }

    public function targetUserId(): UserId { return $this->targetUserId; }
    public function workSourceId(): string { return $this->workSourceId; }
    public function value(): RatingValue { return $this->value; }
    public function assessedAt(): ?DateTimeImmutable { return $this->assessedAt; }
    public function readingRoundSourceId(): ?string { return $this->readingRoundSourceId; }

    public function canonicalPayload(): array
    {
        return [
            "target_kind" => "rating",
            "target_user_id" => $this->targetUserId->value(),
            "work_source_id" => $this->workSourceId,
            "reading_round_source_id" => $this->readingRoundSourceId,
            "rating_half_units" => $this->value->halfUnits(),
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
