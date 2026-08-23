<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Assessments\AssessmentClock;
use Biblio\Core\Assessments\AssessmentStale;
use Biblio\Core\Assessments\WritableRatingRepository;
use Biblio\Core\Assessments\WritableReviewRepository;
use Biblio\Core\Reading\ReadingRoundDeletionNotAllowed;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundNotAvailable;
use Biblio\Core\Reading\ReadingRoundProvenance;
use Biblio\Core\Reading\ReadingRoundStale;
use Biblio\Core\Reading\ReadingRoundVersion;
use Biblio\Core\Reading\WritableReadingRoundRepository;

final readonly class DeleteHistoricalReadingRoundService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WritableReadingRoundRepository $rounds,
        private TransactionManager $transactions,
        private ?WritableRatingRepository $ratings = null,
        private ?WritableReviewRepository $reviews = null,
        private ?AssessmentClock $assessmentClock = null
    ) {
    }

    public function delete(
        ReadingRoundId $id,
        ReadingRoundVersion $expectedVersion,
        ?ContributionRoundDeletionChoice $ratingChoice = null,
        ?ContributionRoundDeletionChoice $reviewChoice = null
    ): void {
        $actorId = $this->authenticatedUser->requireUserId();

        $this->transactions->run(function () use (
            $actorId,
            $id,
            $expectedVersion
            ,$ratingChoice
            ,$reviewChoice
        ): void {
            $current = $this->rounds->findForUserForUpdate($id, $actorId);

            if ($current === null) {
                throw new ReadingRoundNotAvailable();
            }

            if ($current->provenance() !== ReadingRoundProvenance::HistoricalManual) {
                throw new ReadingRoundDeletionNotAllowed();
            }

            if (!$current->version()->equals($expectedVersion)) {
                throw new ReadingRoundStale($current);
            }

            $this->resolveContributions(
                $actorId,
                $id,
                $ratingChoice,
                $reviewChoice
            );

            if (!$this->rounds->deleteHistoricalIfVersionMatches(
                $actorId,
                $id,
                $expectedVersion
            )) {
                throw new ReadingRoundStale($current);
            }
        });
    }

    private function resolveContributions(
        \Biblio\Core\Identity\UserId $actorId,
        ReadingRoundId $roundId,
        ?ContributionRoundDeletionChoice $ratingChoice,
        ?ContributionRoundDeletionChoice $reviewChoice
    ): void {
        if ($this->ratings !== null) {
            foreach ($this->ratings->findForUserAndRound($actorId, $roundId) as $rating) {
                if ($ratingChoice === null) {
                    throw new ReadingRoundDeletionNotAllowed();
                }
                $ok = $ratingChoice === ContributionRoundDeletionChoice::DeleteContribution
                    ? $this->ratings->deleteIfVersionMatches($actorId, $rating->id(), $rating->version())
                    : $this->ratings->replaceIfVersionMatches(
                        $actorId,
                        $rating->withReadingRound(null, $this->requiredAssessmentClock()->now()),
                        $rating->version()
                    );
                if (!$ok) { throw new AssessmentStale(); }
            }
        }
        if ($this->reviews !== null) {
            foreach ($this->reviews->findForUserAndRound($actorId, $roundId) as $review) {
                if ($reviewChoice === null) {
                    throw new ReadingRoundDeletionNotAllowed();
                }
                $ok = $reviewChoice === ContributionRoundDeletionChoice::DeleteContribution
                    ? $this->reviews->deleteIfVersionMatches($actorId, $review->id(), $review->version())
                    : $this->reviews->replaceIfVersionMatches(
                        $actorId,
                        $review->withReadingRound(null, $this->requiredAssessmentClock()->now()),
                        $review->version()
                    );
                if (!$ok) { throw new AssessmentStale(); }
            }
        }
    }

    private function requiredAssessmentClock(): AssessmentClock
    {
        if ($this->assessmentClock === null) {
            throw new ReadingRoundDeletionNotAllowed();
        }
        return $this->assessmentClock;
    }
}
