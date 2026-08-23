<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use Biblio\Core\Catalog\WorkId; use Biblio\Core\Identity\UserId; use Biblio\Core\Library\LibraryId;
interface WritablePublicationRepository
{
    public function add(ContributionPublication $publication): void;
    public function findForOwnerForUpdate(PublicationId $id, UserId $owner): ?ContributionPublication;
    public function findForOwner(PublicationId $id, UserId $owner): ?ContributionPublication;
    public function findInLibraryForUpdate(PublicationId $id, LibraryId $library): ?ContributionPublication;
    public function findInLibrary(PublicationId $id, LibraryId $library): ?ContributionPublication;
    public function findRatingHistory(RatingId $id, LibraryId $library): ?ContributionPublication;
    public function findReviewHistory(ReviewId $id, LibraryId $library): ?ContributionPublication;
    public function replaceIfVersionMatches(ContributionPublication $replacement, PublicationVersion $expected): bool;
    /** @return list<PublicRating> */ public function publicRatings(LibraryId $library, WorkId $work): array;
    /** @return list<PublicReview> */ public function publicReviews(LibraryId $library, WorkId $work): array;
    public function publicAverage(LibraryId $library, WorkId $work): RatingAggregate;
}
