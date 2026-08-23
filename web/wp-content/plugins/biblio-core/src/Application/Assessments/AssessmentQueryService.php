<?php
declare(strict_types=1);
namespace Biblio\Core\Application\Assessments;
use Biblio\Core\Application\Identity\AuthenticatedUser;use Biblio\Core\Application\Library\LibraryAccessService;use Biblio\Core\Assessments\{PublicationId,PublicationIneligible,PublicationNotAvailable,PublicationState,Rating,RatingAggregate,RatingId,RatingNotAvailable,ReviewId,ReviewNotAvailable,WritablePublicationRepository,WritableRatingRepository,WritableReviewRepository,WrittenReview};use Biblio\Core\Catalog\WorkId;use Biblio\Core\Library\{LibraryContext,LibraryId};use Biblio\Core\Reading\ReadingRoundId;
final readonly class AssessmentQueryService
{
    public function __construct(private AuthenticatedUser $auth,private LibraryAccessService $access,private WritableRatingRepository $ratings,private WritableReviewRepository $reviews,private WritablePublicationRepository $publications){}
    public function ownRating(RatingId $id):Rating{$r=$this->ratings->findForUser($id,$this->auth->requireUserId());if($r===null)throw new RatingNotAvailable();return $r;}
    public function ownReview(ReviewId $id):WrittenReview{$r=$this->reviews->findForUser($id,$this->auth->requireUserId());if($r===null)throw new ReviewNotAvailable();return $r;}
    /** @return list<Rating> */ public function ownRatingsForWork(WorkId $work):array{return $this->ratings->findForUserAndWork($this->auth->requireUserId(),$work);}
    /** @return list<WrittenReview> */ public function ownReviewsForWork(WorkId $work):array{return $this->reviews->findForUserAndWork($this->auth->requireUserId(),$work);}
    /** @return list<Rating> */ public function ownRatingsForRound(ReadingRoundId $round):array{return $this->ratings->findForUserAndRound($this->auth->requireUserId(),$round);}
    /** @return list<WrittenReview> */ public function ownReviewsForRound(ReadingRoundId $round):array{return $this->reviews->findForUserAndRound($this->auth->requireUserId(),$round);}
    /** @return list<Rating> */ public function myRatings(int $limit=50):array{return $this->ratings->findAllForUser($this->auth->requireUserId(),$limit);}
    /** @return list<WrittenReview> */ public function myReviews(int $limit=50):array{return $this->reviews->findAllForUser($this->auth->requireUserId(),$limit);}
    public function ownAverage(WorkId $work):RatingAggregate{$ratings=$this->ownRatingsForWork($work);$sum=array_sum(array_map(fn(Rating $r):int=>$r->value()->halfUnits(),$ratings));return new RatingAggregate(count($ratings),$sum,$ratings===[]?0:1);}
    public function ownPublicationState(PublicationId $id):PublicationState{$publication=$this->publications->findForOwner($id,$this->auth->requireUserId());if($publication===null)throw new PublicationNotAvailable();return PublicationState::fromPublication($publication);}
    public function moderationState(PublicationId $id,LibraryId $library):PublicationState{$actor=$this->auth->requireUserId();if(!$this->access->canModerateContribution(new LibraryContext($library,$actor)))throw new PublicationIneligible();$publication=$this->publications->findInLibrary($id,$library);if($publication===null)throw new PublicationNotAvailable();return PublicationState::fromPublication($publication);}
    /** @return list<\Biblio\Core\Assessments\PublicRating> */ public function publicRatings(LibraryId $library,WorkId $work):array{return $this->publications->publicRatings($library,$work);}
    /** @return list<\Biblio\Core\Assessments\PublicReview> */ public function publicReviews(LibraryId $library,WorkId $work):array{return $this->publications->publicReviews($library,$work);}
    public function publicAverage(LibraryId $library,WorkId $work):RatingAggregate{return $this->publications->publicAverage($library,$work);}
}
