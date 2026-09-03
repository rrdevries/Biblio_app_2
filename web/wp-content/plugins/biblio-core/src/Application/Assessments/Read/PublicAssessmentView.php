<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Assessments\Read;

use Biblio\Core\Assessments\RatingValue;
use DateTimeImmutable;
use LogicException;

final readonly class PublicAssessmentView
{
    public function __construct(
        private PublicAssessmentKind $kind,
        private string $displayName,
        private ?RatingValue $rating,
        private ?string $escapedReviewText,
        private DateTimeImmutable $publishedAt
    ) {
        if (
            ($kind === PublicAssessmentKind::Rating && (
                $rating === null || $escapedReviewText !== null
            ))
            || ($kind === PublicAssessmentKind::Review
                && $escapedReviewText === null)
        ) {
            throw new LogicException("Invalid public assessment projection.");
        }
    }

    public function kind(): PublicAssessmentKind
    {
        return $this->kind;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function rating(): ?RatingValue
    {
        return $this->rating;
    }

    public function escapedReviewText(): ?string
    {
        return $this->escapedReviewText;
    }

    public function publishedAt(): DateTimeImmutable
    {
        return $this->publishedAt;
    }
}
