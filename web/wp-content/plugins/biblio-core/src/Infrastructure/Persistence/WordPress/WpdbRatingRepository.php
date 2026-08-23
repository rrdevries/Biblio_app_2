<?php
declare(strict_types=1);
namespace Biblio\Core\Infrastructure\Persistence\WordPress;
use Biblio\Core\Assessments\{ContributionDuplicate,Rating,RatingId,RatingIdCollision,RatingValue,RatingVersion,WritableRatingRepository};
use Biblio\Core\Catalog\WorkId; use Biblio\Core\Exception\{AuthorizationException,FailureReason}; use Biblio\Core\Identity\UserId; use Biblio\Core\Infrastructure\Persistence\PersistenceException; use Biblio\Core\Reading\ReadingRoundId; use DateTimeImmutable; use DateTimeZone; use Throwable; use wpdb;
final readonly class WpdbRatingRepository implements WritableRatingRepository
{
    private const FORMAT='Y-m-d H:i:s.u';
    public function __construct(private wpdb $db, private CoreTableNames $tables) {}
    public function addForUser(UserId $actor, Rating $rating): void
    {
        $this->assertOwner($actor,$rating); $this->assertRound($rating); $old=$this->db->suppress_errors(true);
        try { $ok=$this->db->insert($this->tables->ratings(),['rating_id'=>$rating->id()->value(),'user_id'=>$rating->userId()->value(),'work_id'=>$rating->workId()->value(),'reading_round_id'=>$rating->readingRoundId()?->value(),'rating_half_units'=>$rating->value()->halfUnits(),'created_at'=>$this->format($rating->createdAt()),'updated_at'=>$this->format($rating->updatedAt()),'rating_version'=>$rating->version()->value()],['%s','%s','%s','%s','%d','%s','%s','%d']); } finally { $this->db->suppress_errors($old); }
        if ($ok===1) return; $conflict=WpdbErrorTranslator::conflict($this->db->last_error); if ($conflict !== null) { if ($conflict->constraintName()==='PRIMARY') throw new RatingIdCollision(); throw new ContributionDuplicate(); } throw WpdbErrorTranslator::writeFailure('Could not persist Rating.',$this->db->last_error);
    }
    public function findForUser(RatingId $id,UserId $user): ?Rating { return $this->one($id,$user,false); }
    public function findForUserForUpdate(RatingId $id,UserId $user): ?Rating { return $this->one($id,$user,true); }
    public function findForUserAndWork(UserId $user,WorkId $work): array { return $this->many('user_id=%s AND work_id=%s',[$user->value(),$work->value()]); }
    public function findForUserAndRound(UserId $user,ReadingRoundId $round): array { return $this->many('user_id=%s AND reading_round_id=%s',[$user->value(),$round->value()]); }
    public function findAllForUser(UserId $user,int $limit=50): array { $limit=max(1,min(100,$limit)); return $this->many('user_id=%s',[$user->value()],$limit); }
    public function replaceIfVersionMatches(UserId $actor,Rating $r,RatingVersion $expected): bool
    {
        $this->assertOwner($actor,$r); $this->assertRound($r); if ($r->version()->value()!==$expected->value()+1) throw new PersistenceException('Rating replacement must increment once.',failureReason:FailureReason::PersistenceWriteFailed);
        $round=$r->readingRoundId()===null?'NULL':$this->db->prepare('%s',$r->readingRoundId()->value()); $table=$this->tables->ratings();
        $old = $this->db->suppress_errors(true);
        try {
            $result=$this->db->query($this->db->prepare("UPDATE `{$table}` SET reading_round_id={$round},rating_half_units=%d,updated_at=%s,rating_version=%d WHERE rating_id=%s AND user_id=%s AND work_id=%s AND rating_version=%d",$r->value()->halfUnits(),$this->format($r->updatedAt()),$r->version()->value(),$r->id()->value(),$actor->value(),$r->workId()->value(),$expected->value()));
        } finally {
            $this->db->suppress_errors($old);
        }
        if ($result===false) { if (WpdbErrorTranslator::conflict($this->db->last_error)!==null) throw new ContributionDuplicate(); throw WpdbErrorTranslator::writeFailure('Could not update Rating.',$this->db->last_error); } return $result===1;
    }
    public function deleteIfVersionMatches(UserId $actor,RatingId $id,RatingVersion $expected): bool { $t=$this->tables->ratings(); $r=$this->db->query($this->db->prepare("DELETE FROM `{$t}` WHERE rating_id=%s AND user_id=%s AND rating_version=%d",$id->value(),$actor->value(),$expected->value())); if($r===false) throw WpdbErrorTranslator::writeFailure('Could not delete Rating.',$this->db->last_error); return $r===1; }
    private function one(RatingId $id,UserId $user,bool $lock): ?Rating { $t=$this->tables->ratings(); $row=$this->db->get_row($this->db->prepare("SELECT rating_id,user_id,work_id,reading_round_id,rating_half_units,created_at,updated_at,rating_version FROM `{$t}` WHERE rating_id=%s AND user_id=%s".($lock?' FOR UPDATE':''),$id->value(),$user->value())); return $row===null?null:$this->hydrate($row); }
    /**
     * @param list<string|int> $args
     * @return list<Rating>
     */
    private function many(string $where,array $args,int $limit=100): array { $t=$this->tables->ratings(); $args[]=$limit; $rows=$this->db->get_results($this->db->prepare("SELECT rating_id,user_id,work_id,reading_round_id,rating_half_units,created_at,updated_at,rating_version FROM `{$t}` WHERE {$where} ORDER BY updated_at DESC,rating_id DESC LIMIT %d",...$args)); return array_map($this->hydrate(...),$rows); }
    private function hydrate(object $r): Rating { try { return new Rating(new RatingId((string)$r->rating_id),new UserId((string)$r->user_id),new WorkId((string)$r->work_id),$r->reading_round_id===null?null:new ReadingRoundId((string)$r->reading_round_id),RatingValue::fromHalfUnits((int)$r->rating_half_units),$this->date($r->created_at),$this->date($r->updated_at),new RatingVersion((int)$r->rating_version)); } catch(Throwable $e){ throw new PersistenceException('Stored Rating is invalid.',0,$e,FailureReason::PersistenceReadFailed); } }
    private function assertOwner(UserId $actor,Rating $r): void { if(!$actor->equals($r->userId())) throw new AuthorizationException('Cannot persist Rating for another user.'); }
    private function assertRound(Rating $r): void { if($r->readingRoundId()===null)return; $t=$this->tables->readingRounds(); $match=$this->db->get_var($this->db->prepare("SELECT reading_round_id FROM `{$t}` WHERE reading_round_id=%s AND user_id=%s AND work_id=%s",$r->readingRoundId()->value(),$r->userId()->value(),$r->workId()->value())); if(!is_string($match)) throw new PersistenceException('Rating ReadingRound context is inconsistent.',failureReason:FailureReason::PersistenceWriteFailed); }
    private function format(DateTimeImmutable $d): string { return $d->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT); }
    private function date(mixed $v): DateTimeImmutable { $d=DateTimeImmutable::createFromFormat('!'.self::FORMAT,(string)$v,new DateTimeZone('UTC')); if($d===false) throw new PersistenceException('Stored Rating instant invalid.',failureReason:FailureReason::PersistenceReadFailed); return $d; }
}
