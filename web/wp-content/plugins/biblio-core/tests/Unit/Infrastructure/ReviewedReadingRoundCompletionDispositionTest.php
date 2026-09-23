<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Migration\Cutover\{FinalPopulationContractBundle,FinalSourceCompatibilityReport,FinalSourceDriftEngine,FinalSourceObservation,FinalSourcePackageIdentity,FinalSourceSnapshot,ReviewedReadingRoundCompletionDisposition,SourceDrift,SourceDriftCategory};
use Biblio\Core\Application\Migration\Runner\{DeterministicJson,MigrationSourceInspection,MigrationSourcePackage,MigrationSourceProfile,MigrationSourceRecord,MigrationPlanningTarget};
use Biblio\Core\Infrastructure\Migration\{CurrentV1SourceAdapter,CurrentV1ReadingMapper,CurrentV1ReviewedReadingContract};
use Biblio\Core\Application\Identity\{PersonalMigrationTarget,PersonalMigrationTargetReadiness};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\{LibraryId,LibraryName};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviewedReadingRoundCompletionDispositionTest extends TestCase
{
    private const BOOK = '1771014295306';
    private const ROUND = 'rr-1-20260915T133135697-na';
    private const OLD = '06e3d14f8a801bc2094333ae5f8fe97e1e388c3fe076202e883d05416e75dff7';
    private const NEW = '145d3f0e42f2451e0d68a63c1c6bd0aa60e33faea0278df7a081ffb9b08abdf0';

    public function testExactClosedDispositionPreservesMechanicalEvidenceAndBundleBinding(): void
    {
        $a=$this->snapshot(false); $b=$this->snapshot(true);
        $report=(new FinalSourceDriftEngine())->compare($a,$b); $row=$this->target($report)->toArray();
        self::assertSame('E',$row['category']); self::assertSame('C',$row['effective_category']);
        self::assertTrue($row['contract_review_required']); self::assertFalse($row['effective_contract_review_required']);
        self::assertSame('new_raw_value_enum_or_source_shape',$row['reason_code']);
        self::assertSame(self::OLD,$row['reference_payload_hash']); self::assertSame(self::NEW,$row['candidate_payload_hash']);
        $review=$row['reviewed_disposition'];
        self::assertSame('CURRENT USER DATA — EXISTING CONTRACT COMPATIBLE',$review['reviewed_disposition']);
        self::assertSame(['operational'=>'day','exact_clock_completion_proven'=>false],$review['precision']);
        self::assertFalse($review['preservation']['new_preservation_plan_or_reason']);
        self::assertFalse($review['preservation']['derived_history_creates_rounds']);
        self::assertSame('review-user',$review['reviewed_mapper_effect']['diagnostic_target_user_id']);
        self::assertSame('MAPPING_CONTRACT_COMPATIBLE',$report->compatibility());
        self::assertSame(1,$report->toArray()['category_counts']['E']);
        self::assertSame(0,$report->toArray()['effective_category_counts']['E']);
        $bundle=FinalPopulationContractBundle::fromReport($b,$report);
        self::assertSame('reviewed_compatible',$bundle->approvalState()->value);
        self::assertSame($report->reviewedDispositions(),$bundle->toArray()['reviewed_dispositions']);
        self::assertSame($bundle->digest(),FinalPopulationContractBundle::fromReport($b,(new FinalSourceDriftEngine())->compare($a,$b))->digest());
        $mechanical=new FinalSourceCompatibilityReport($a,$b,[new SourceDrift('reading_rounds','v1.reading_round',self::ROUND,SourceDriftCategory::E,'new_raw_value_enum_or_source_shape',self::OLD,self::NEW)]);
        self::assertNotSame($report->digest(),$mechanical->digest());
        self::assertNotSame($bundle->digest(),FinalPopulationContractBundle::fromReport($b,$mechanical)->digest());
    }

    #[DataProvider('packageChanges')]
    public function testEveryPackageBindingFailsClosed(bool $candidate,string $field): void
    {
        $change=[$field=>str_ends_with($field,'sha256')?str_repeat('f',64):'other-value'];
        $this->assertHeld($this->snapshot(false,package:$candidate?[]:$change),$this->snapshot(true,package:$candidate?$change:[]));
    }
    public static function packageChanges(): iterable
    {
        foreach([false,true]as$c)foreach(['logical_package_id','archive_sha256','manifest_sha256','source_family','source_version','adapter_id']as$f)yield ($c?'new ':'old ').$f=>[$c,$f];
    }

    #[DataProvider('unapprovedPayloads')]
    public function testUnapprovedRawLifecycleOrIdentityNeverReceivesDisposition(bool $candidate,array $payload): void
    {
        $change=['payload_hash'=>DeterministicJson::hash($payload)];
        $this->assertHeld($this->snapshot(false,target:$candidate?[]:$change),$this->snapshot(true,target:$candidate?$change:[]));
    }
    public static function unapprovedPayloads(): iterable
    {
        foreach([false,true]as$c){$base=self::raw($c);
            foreach([
                'different round'=>['id'=>'other-round'], 'changed startedAt'=>['startedAt'=>'2026-09-14T12:00:00.000Z'],
                'changed start partial'=>['startedAtPartial'=>['value'=>'2026-09','precision'=>'month']],
                'changed pauses'=>['pauses'=>[['pausedAt'=>'2026-09-15T14:00:00.000Z']]],
                'different finish'=>['finishedAt'=>'2026-09-17T12:00:00.000Z','finishedAtPartial'=>['value'=>'2026-09-17','precision'=>'day']],
                'month precision'=>['finishedAtPartial'=>['value'=>'2026-09','precision'=>'month']],
                'year precision'=>['finishedAtPartial'=>['value'=>'2026','precision'=>'year']],
                'stopped'=>['finishedAt'=>'','finishedAtPartial'=>null,'stoppedAt'=>'2026-09-16T12:00:00.000Z','stoppedAtPartial'=>['value'=>'2026-09-16','precision'=>'day']],
                'contradictory end'=>['stoppedAt'=>'2026-09-16T12:00:00.000Z','stoppedAtPartial'=>['value'=>'2026-09-16','precision'=>'day']],
                'null exact'=>['finishedAt'=>null], 'empty partial'=>['finishedAtPartial'=>''],
                'inferred updatedAt'=>['updatedAt'=>'2026-09-16T12:00:00.000Z'],
                'inferred history'=>['readHistory'=>[['event'=>'status','status'=>'finished','at'=>'2026-09-16T12:00:00.000Z']]],
                'item inference'=>['source'=>['item_id'=>'synthetic-item']], 'reread'=>['reread'=>true],
            ]as$label=>$changes){$p=$base;$p['record']=array_replace($p['record'],$changes);if($p!==$base)yield ($c?'new ':'old ').$label=>[$c,$p];}
            $p=$base;$p['book_id']='another-book';yield ($c?'new ':'old ').'book'=>[$c,$p];
            foreach(['finishedAt','finishedAtPartial']as$f){$p=$base;unset($p['record'][$f]);yield ($c?'new ':'old ').'absent '.$f=>[$c,$p];}
        }
        $p=self::raw(true);$p['record']['finishedAt']='';yield 'finish removed'=>[true,$p];
        $p=self::raw(true);$p['record']['finishedAtPartial']=null;yield 'partial removed'=>[true,$p];
        yield 'completed to active'=>[true,self::raw(false)];
        yield 'completed already in reference'=>[false,self::raw(true)];
    }

    public function testPopulationShapeStateAndInventoryCannotBeBroadened(): void
    {
        foreach([['source_id'=>'other-round'],['source_type'=>'v1.other'],['shape_hash'=>str_repeat('f',64)],['semantic_class'=>'unreviewed'],['state'=>'conflict'],['structural'=>true]]as$c)$this->assertHeld($this->snapshot(false),$this->snapshot(true,target:$c));
        $rows=$this->rows(true);$rows[]=[...$rows[0],'source_id'=>'extra-round'];$this->assertHeld($this->snapshot(false),$this->snapshot(true,rows:$rows));
        $rows=$this->rows(true);$rows[]=[...$rows[0],'source_type'=>'v1.unexpected','source_id'=>'extra-unknown'];$this->assertHeld($this->snapshot(false),$this->snapshot(true,rows:$rows));
        $rows=$this->rows(true);array_pop($rows);$this->assertHeld($this->snapshot(false),$this->snapshot(true,rows:$rows));
        $rows=$this->rows(true);$rows[0]['payload_hash']=str_repeat('a',64);$this->assertHeld($this->snapshot(false),$this->snapshot(true,rows:$rows));
        $this->assertHeld($this->snapshot(false),$this->snapshot(true,count:54));
    }

    public function testReportRebindingIsRejected(): void
    {
        $a=$this->snapshot(false);$report=(new FinalSourceDriftEngine())->compare($a,$this->snapshot(true));
        $this->expectException(\Biblio\Core\Exception\ValidationException::class);
        new FinalSourceCompatibilityReport($a,$this->snapshot(true,package:['logical_package_id'=>'other']),$report->drift());
    }

    public function testAllThreeClosedDispositionsComposeWithoutAcceptingAdditionalDrift(): void
    {
        $a=$this->snapshot(false,extra:$this->otherApprovedObservations(false));
        $b=$this->snapshot(true,extra:$this->otherApprovedObservations(true));
        $report=(new FinalSourceDriftEngine())->compare($a,$b);
        self::assertCount(3,$report->reviewedDispositions());
        self::assertSame('MAPPING_CONTRACT_COMPATIBLE',$report->compatibility());
        self::assertSame(2,$report->toArray()['category_counts']['E']);self::assertSame(1,$report->toArray()['category_counts']['I']);
        self::assertSame(0,$report->toArray()['effective_category_counts']['E']);self::assertSame(0,$report->toArray()['effective_category_counts']['I']);
        self::assertCount(3,FinalPopulationContractBundle::fromReport($b,$report)->toArray()['reviewed_dispositions']);
        $old=new FinalSourceObservation('catalog_books','v1.book','unreviewed',str_repeat('a',64),str_repeat('b',64),'ordinary');
        $new=new FinalSourceObservation('catalog_books','v1.book','unreviewed',str_repeat('c',64),str_repeat('d',64),'ordinary');
        $report=(new FinalSourceDriftEngine())->compare($this->snapshot(false,extra:[...$this->otherApprovedObservations(false),$old]),$this->snapshot(true,extra:[...$this->otherApprovedObservations(true),$new]));
        self::assertCount(3,$report->reviewedDispositions());self::assertSame('CONTRACT_REVIEW_REQUIRED',$report->compatibility());
    }

    public function testOtherUnreviewedDriftAndOpenCirculationRemainIndependentGates(): void
    {
        $old=new FinalSourceObservation('catalog_books','v1.book','other',str_repeat('a',64),str_repeat('b',64),'ordinary');
        $new=new FinalSourceObservation('catalog_books','v1.book','other',str_repeat('c',64),str_repeat('d',64),'ordinary');
        $report=(new FinalSourceDriftEngine())->compare($this->snapshot(false,extra:[$old]),$this->snapshot(true,extra:[$new]));
        self::assertSame('C',$this->target($report)->effectiveCategory()->value);self::assertSame('CONTRACT_REVIEW_REQUIRED',$report->compatibility());
        self::assertSame('CIRCULATION_CUTOVER_REVIEW_REQUIRED',$report->toArray()['circulation_cutover_gate']);
        $report=(new FinalSourceDriftEngine())->compare($this->snapshot(false),$this->snapshot(true));
        self::assertSame('MAPPING_CONTRACT_COMPATIBLE',$report->compatibility());self::assertSame('CIRCULATION_CUTOVER_REVIEW_REQUIRED',$report->toArray()['circulation_cutover_gate']);
    }

    public function testUnchangedMapperAddsOnlyCompletedDayPlanAndNeverChangesTruthOrPromotesClockTime(): void
    {
        self::assertSame(self::OLD,DeterministicJson::hash(self::raw(false)));self::assertSame(self::NEW,DeterministicJson::hash(self::raw(true)));
        $old=$this->map(false);$new=$this->map(true);
        self::assertSame($old['truth'],$new['truth']);self::assertCount(3,$new['truth']);self::assertCount(0,$old['rounds']);self::assertCount(1,$new['rounds']);
        self::assertSame(['target_kind'=>'reading_round','target_user_id'=>'review-user','work_source_id'=>'v1.book/'.self::BOOK.'/work','lifecycle'=>'ended','outcome'=>'completed','period'=>['started_on'=>['year'=>2026,'month'=>9,'day'=>15],'finished_on'=>['year'=>2026,'month'=>9,'day'=>16]],'source'=>null,'provenance'=>'migration_imported'],$new['rounds'][self::ROUND]);
        self::assertSame('87bc9d8696b1fa30124e7f5fea9d2be41af713e3f968421f35a76bf5a64f867e',DeterministicJson::hash($new['rounds'][self::ROUND]));
        self::assertStringNotContainsString('12:00',DeterministicJson::encode($new['rounds']));
        self::assertNotSame($new['truth'],$this->map(true,changeTruth:true)['truth']);
        // Changed PRT source changes the verified manifest, so it cannot reuse this approval.
        $this->assertHeld($this->snapshot(false),$this->snapshot(true,package:['manifest_sha256'=>str_repeat('e',64)]));
    }

    private function map(bool $candidate,bool $changeTruth=false): array
    {
        $raw=self::raw($candidate);$books=[];$rounds=[];$reps=[];
        $book=new MigrationSourceRecord('v1.book',self::BOOK,['id'=>self::BOOK,'readingRounds'=>[$raw['record']],'readStatus'=>$candidate?'finished':'reading','readMarker'=>'yes','readHistory'=>$candidate?[['event'=>'status','status'=>'finished','at'=>'2026-09-16T12:00:00.000Z'],['event'=>'status','status'=>'finished','at'=>'2026-09-22T17:28:15.656Z']]:[]]);$books['source:'.self::BOOK]=$book;$reps[self::BOOK]=self::BOOK;
        foreach(['known'=>['finished','yes',['mode'=>'unknown_date']],'unread'=>[$changeTruth?'unknown':'unread',$changeTruth?'unknown':'no',null],'unknown'=>['unknown','unknown',null]]as$id=>[$status,$marker,$registration]){$books['source:'.$id]=new MigrationSourceRecord('v1.book',$id,['id'=>$id,'readingRounds'=>[],'readRegistration'=>$registration,'readStatus'=>$status,'readMarker'=>$marker,'readHistory'=>[]]);$reps[$id]=$id;}
        $rounds['source:'.self::ROUND]=new MigrationSourceRecord('v1.reading_round',self::ROUND,$raw,['v1.book:'.self::BOOK]);
        $digest=str_repeat('a',64);$inspection=new MigrationSourceInspection(new MigrationSourcePackage('/synthetic',[],$digest),new CurrentV1SourceAdapter(),new MigrationSourceProfile(CurrentV1SourceAdapter::SOURCE_VERSION,[]),[...array_values($books),...array_values($rounds)],[]);
        $target=new MigrationPlanningTarget(new PersonalMigrationTarget(new UserId('review-user'),new LibraryId('review-library'),LibraryName::personalDefault(),new PersonalMigrationTargetReadiness([])));
        $mapped=(new CurrentV1ReadingMapper(new CurrentV1ReviewedReadingContract($digest)))->map($inspection,$target,$books,$rounds,$reps);$out=['truth'=>[],'rounds'=>[]];foreach($mapped->records()as$r)$out[$r->sourceType()==='reading_truth'?'truth':'rounds'][$r->sourceId()]=$r->payload();return $out;
    }
    private static function raw(bool $candidate): array
    {
        return ['book_id'=>self::BOOK,'record'=>['id'=>self::ROUND,'startedAt'=>'2026-09-15T13:31:35.697Z','startedAtPartial'=>['value'=>'2026-09-15','precision'=>'day'],'pauses'=>[],'finishedAt'=>$candidate?'2026-09-16T12:00:00.000Z':'','finishedAtPartial'=>$candidate?['value'=>'2026-09-16','precision'=>'day']:null,'stoppedAt'=>'','stoppedAtPartial'=>null,'stopReason'=>'']];
    }
    private function otherApprovedObservations(bool $candidate): array
    {
        $shape='dcb452a982945e5e2957930d83d36af5ceee19805ec0c3b30529ae8f44f6e49e';
        $out=[new FinalSourceObservation('catalog_books','v1.book',self::BOOK,$candidate?'d7228e61b0c367a78dce0fe5bdd1a8cfdbebc36ce97864df208f8294f7490c0a':'6e4a63b4ef007488a788ad4b7c1dc74a2e4b6cc5ae773a0a23193e6baaaa6f72',$candidate?'768ef09a41016085f7834f467585c01c7ce5e9af3d991315a38b441e11388c0d':'b955461a1b74ed184d83f00fbbf1a6a5d25365dbad580e10a3d51fff2f8d6710','reviewed_current_v1_shape'),new FinalSourceObservation('authors_contributors','v1.contributor_vector','v1.book/'.self::BOOK.'/contributors','4ba30f5f02b3d18cfd2895913bd9209dad78b9a8415bffc856d990dc1fb88c00',$shape,'reviewed_current_v1_shape'),new FinalSourceObservation('classifications','v1.classification_review_vector','classification-review-queue',$candidate?'4a0e25f651005c83f021fd9d7350cbab03196fff2c9dd663affb520e28ab0bcd':'a37d40c73f73b496500d87cbc564127b00d54f828c0e34739fb3a1864ea4b864',$shape,'reviewed_current_v1_shape',true)];
        $fixture=json_decode(file_get_contents(dirname(__DIR__,2).'/Fixtures/final-classification-reviewed-observations.json'),true,512,JSON_THROW_ON_ERROR);
        foreach($fixture['groups']as$g)foreach($g['source_ids']as$id){$r=$g['observation'];$out[]=new FinalSourceObservation($r['domain'],$r['source_type'],$id,$r['payload_hash'],$r['shape_hash'],$r['semantic_class'],$r['structural'],$r['state']);}return $out;
    }
    private function assertHeld(FinalSourceSnapshot $a,FinalSourceSnapshot $b): void
    {
        self::assertNull(ReviewedReadingRoundCompletionDisposition::forSnapshots($a,$b));
        $r=(new FinalSourceDriftEngine())->compare($a,$b);self::assertSame([],$r->reviewedDispositions());
        foreach($r->drift()as$d)self::assertSame($d->category(),$d->effectiveCategory());
    }
    private function target(FinalSourceCompatibilityReport $r): SourceDrift
    {
        foreach($r->drift()as$d)if($d->toArray()['source_id']===self::ROUND)return $d;self::fail('Missing target drift.');
    }
    private function rows(bool $candidate): array
    {
        $rows=json_decode(file_get_contents(dirname(__DIR__,2).'/Fixtures/final-reading-reviewed-observations.json'),true,512,JSON_THROW_ON_ERROR);
        foreach($rows as &$r)if($r['source_id']===self::ROUND&&$candidate){$r['payload_hash']=self::NEW;$r['shape_hash']='a14e8975a1c1b840e8ab2eb1e54f4da197ebb06ef1349d74f1f78d9f37db98af';}unset($r);return $rows;
    }
    private function snapshot(bool $candidate,array $package=[],array $target=[],?array $rows=null,array $extra=[],int $count=53): FinalSourceSnapshot
    {
        $p=array_replace(['logical_package_id'=>$candidate?'final-current-20260923t124538449z-89b2ba3e94f9':'reviewed-current-35a18156490f','archive_sha256'=>$candidate?'89b2ba3e94f916d45e63f5aaa3595afac6241473f89d11eb062a165f053c8d9a':'835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c','manifest_sha256'=>$candidate?'43306b888056daeed2c3837aba6538befbb94caccc43b1c5d37c77dc8ff52480':'35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67','source_family'=>'biblio-v1','source_version'=>'books-29.authors-2.reading-goals-2','adapter_id'=>'current-v1-json-29'],$package);
        $rows??=$this->rows($candidate);foreach($rows as &$r)if($r['source_id']===self::ROUND)$r=array_replace($r,$target);unset($r);
        return new FinalSourceSnapshot(new FinalSourcePackageIdentity(...array_values($p)),[...array_map(static fn(array $r)=>new FinalSourceObservation(...array_values($r)),$rows),...$extra],['v1.reading_round'=>$count],['open_total'=>1],[]);
    }
}
