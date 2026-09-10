<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Assessments;

use Biblio\Core\Application\Identity\PlatformUserDirectory;
use Biblio\Core\Assessments\AssessmentClock;
use Biblio\Core\Assessments\AssessmentIdCollisionExhausted;
use Biblio\Core\Assessments\Rating;
use Biblio\Core\Assessments\RatingIdCollision;
use Biblio\Core\Assessments\RatingIdGenerator;
use Biblio\Core\Assessments\RatingNotAvailable;
use Biblio\Core\Assessments\RatingValue;
use Biblio\Core\Assessments\ReviewContent;
use Biblio\Core\Assessments\ReviewIdCollision;
use Biblio\Core\Assessments\ReviewIdGenerator;
use Biblio\Core\Assessments\ReviewNotAvailable;
use Biblio\Core\Assessments\WritableRatingRepository;
use Biblio\Core\Assessments\WritableReviewRepository;
use Biblio\Core\Assessments\WrittenReview;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Catalog\WorkRepository;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundRepository;
use DateTimeImmutable;

/**
 * Source-neutral participant for an existing transaction.
 *
 * MIG-FND supplies the validated owner and owns the transaction. This class
 * writes private product sources only; publication is deliberately absent.
 */
final readonly class HistoricalAssessmentRecorder
{
    public function __construct(
        private PlatformUserDirectory $users,
        private WorkRepository $works,
        private ReadingRoundRepository $rounds,
        private WritableRatingRepository $ratings,
        private WritableReviewRepository $reviews,
        private RatingIdGenerator $ratingIds,
        private ReviewIdGenerator $reviewIds,
        private AssessmentClock $clock
    ) {
    }

    public function recordRatingForOwner(
        UserId $ownerId,
        WorkId $workId,
        ?ReadingRoundId $readingRoundId,
        RatingValue $value,
        ?DateTimeImmutable $assessedAt
    ): Rating {
        $this->assertContext($ownerId, $workId, $readingRoundId, true);

        $attempt = 0;
        while (true) {
            $recordedAt = $this->clock->now();
            $rating = Rating::historical(
                $this->ratingIds->next(),
                $ownerId,
                $workId,
                $readingRoundId,
                $value,
                $assessedAt,
                $recordedAt
            );
            try {
                $this->ratings->addForUser($ownerId, $rating);
                return $rating;
            } catch (RatingIdCollision $collision) {
                if ($attempt++ >= 3) {
                    throw new AssessmentIdCollisionExhausted($collision);
                }
            }
        }
    }

    public function recordReviewForOwner(
        UserId $ownerId,
        WorkId $workId,
        ?ReadingRoundId $readingRoundId,
        ReviewContent $content,
        ?DateTimeImmutable $assessedAt
    ): WrittenReview {
        $this->assertContext($ownerId, $workId, $readingRoundId, false);

        $attempt = 0;
        while (true) {
            $recordedAt = $this->clock->now();
            $review = WrittenReview::historical(
                $this->reviewIds->next(),
                $ownerId,
                $workId,
                $readingRoundId,
                $content,
                $assessedAt,
                $recordedAt
            );
            try {
                $this->reviews->addForUser($ownerId, $review);
                return $review;
            } catch (ReviewIdCollision $collision) {
                if ($attempt++ >= 3) {
                    throw new AssessmentIdCollisionExhausted($collision);
                }
            }
        }
    }

    private function assertContext(
        UserId $ownerId,
        WorkId $workId,
        ?ReadingRoundId $readingRoundId,
        bool $rating
    ): void {
        if (!$this->users->isActive($ownerId)) {
            throw new ValidationException(
                "Historical assessment requires an active user."
            );
        }
        if ($this->works->find($workId) === null) {
            throw new ValidationException(
                "Historical assessment requires an existing Work."
            );
        }
        if ($readingRoundId === null) {
            return;
        }

        $round = $this->rounds->findForUserForUpdate(
            $readingRoundId,
            $ownerId
        );
        if ($round === null || !$round->workId()->equals($workId)) {
            if ($rating) {
                throw new RatingNotAvailable();
            }
            throw new ReviewNotAvailable();
        }
    }
}
