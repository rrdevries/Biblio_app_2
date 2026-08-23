<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use Biblio\Core\Catalog\WorkId; use Biblio\Core\Identity\UserId; use Biblio\Core\Reading\ReadingRoundId;
interface WritableReviewRepository
{
    public function addForUser(UserId $actor, WrittenReview $review): void;
    public function findForUser(ReviewId $id, UserId $user): ?WrittenReview;
    public function findForUserForUpdate(ReviewId $id, UserId $user): ?WrittenReview;
    /** @return list<WrittenReview> */ public function findForUserAndWork(UserId $user, WorkId $work): array;
    /** @return list<WrittenReview> */ public function findForUserAndRound(UserId $user, ReadingRoundId $round): array;
    /** @return list<WrittenReview> */ public function findAllForUser(UserId $user, int $limit = 50): array;
    public function replaceIfVersionMatches(UserId $actor, WrittenReview $replacement, ReviewVersion $expected): bool;
    public function deleteIfVersionMatches(UserId $actor, ReviewId $id, ReviewVersion $expected): bool;
}
