<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Assessments\Read;

use Biblio\Core\Assessments\RatingValue;
use DateTimeImmutable;
use LogicException;

final readonly class OwnAssessmentView
{
    public function __construct(
        private OwnAssessmentKind $kind,
        private ?RatingValue $rating,
        private ?string $escapedReviewText,
        private bool $readingRoundLinked,
        private ?DateTimeImmutable $assessedAt
    ) {
        if (
            ($kind === OwnAssessmentKind::Rating
                && ($rating === null || $escapedReviewText !== null))
            || ($kind === OwnAssessmentKind::Review
                && ($rating !== null || $escapedReviewText === null))
        ) {
            throw new LogicException("Invalid own assessment projection.");
        }
    }

    public function kind(): OwnAssessmentKind { return $this->kind; }
    public function rating(): ?RatingValue { return $this->rating; }
    public function escapedReviewText(): ?string { return $this->escapedReviewText; }
    public function readingRoundLinked(): bool { return $this->readingRoundLinked; }
    public function assessedAt(): ?DateTimeImmutable { return $this->assessedAt; }
}
