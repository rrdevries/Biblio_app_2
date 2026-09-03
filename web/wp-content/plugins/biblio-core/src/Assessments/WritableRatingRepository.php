<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use Biblio\Core\Catalog\WorkId; use Biblio\Core\Identity\UserId; use Biblio\Core\Reading\ReadingRoundId;
interface WritableRatingRepository
{
    public function addForUser(UserId $actor, Rating $rating): void;
    public function findForUser(RatingId $id, UserId $user): ?Rating;
    public function findForUserForUpdate(RatingId $id, UserId $user): ?Rating;
    /** @return list<Rating> */ public function findForUserAndWork(UserId $user, WorkId $work): array;
    /** @return list<Rating> */ public function findForUserAndRound(UserId $user, ReadingRoundId $round): array;
    /** @return list<Rating> */ public function findAllForUser(UserId $user, int $limit = 50): array;
    public function aggregateForUserAndWork(UserId $user, WorkId $work): RatingAggregate;
    public function replaceIfVersionMatches(UserId $actor, Rating $replacement, RatingVersion $expected): bool;
    public function deleteIfVersionMatches(UserId $actor, RatingId $id, RatingVersion $expected): bool;
}
