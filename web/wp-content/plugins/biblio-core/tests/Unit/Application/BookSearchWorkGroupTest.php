<?php

declare(strict_types=1);
namespace Biblio\Core\Tests\Unit\Application;
use Biblio\Core\Application\Metadata\GlobalSearch\{BookSearchWorkGroupService,BookSearchWorkProgress};
use Biblio\Core\Application\Metadata\Search\{BibliographicTextSearchQuery,BibliographicWorkSearchProvider,BibliographicWorkSourceProvider,BibliographicLocalWorkQueryMatcher,BibliographicWorkSearchPage,BibliographicWorkSourcePage,BibliographicWorkSearchResult,BibliographicWorkReference,BibliographicProviderEntityIdentity,BibliographicSearchProviderFailure};
use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderIdentityRepository;
use Biblio\Core\Application\Metadata\{ProviderLookupStatus,ProviderFailureReason};
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\{AuthenticationException,ValidationException};
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use Biblio\Core\Identity\UserId;
use PHPUnit\Framework\TestCase;

final class BookSearchWorkGroupTest extends TestCase
{
    private function work(int $order,bool $local=false,string $id=''): BibliographicWorkSearchResult
    {
        return new BibliographicWorkSearchResult($local ? BibliographicWorkReference::canonical(new WorkId($id?:'work-'.$order)) : BibliographicWorkReference::external(BibliographicProviderEntityIdentity::work('open_library',$id?:'/works/OL'.($order+1).'W')),'Work '.$order,[],[],$order);
    }
    private function setupService(?ControllableAuthenticatedUser $actor=null): array
    {
        $local=$this->createMock(BibliographicWorkSearchProvider::class);
        $source=$this->createMock(BibliographicWorkSourceProvider::class);$source->method('key')->willReturn('open_library');
        $matcher=$this->createMock(BibliographicLocalWorkQueryMatcher::class);
        $mappings=$this->createMock(BibliographicProviderIdentityRepository::class);
        $mappings->expects(self::never())->method('claimWork');$mappings->expects(self::never())->method('claimEdition');
        return [new BookSearchWorkGroupService($actor??new ControllableAuthenticatedUser(new UserId('actor-1')),$local,$source,$matcher,$mappings),$local,$source,$matcher,$mappings];
    }
    public function testLocalPagesFinishBeforeAnySourceRequestAndFullFinalPageDefersSource(): void
    {
        $q=new BibliographicTextSearchQuery('Work');[$s,$l,$e,$matcher]=$this->setupService();$matcher->expects(self::never())->method('workMatchesQuery');$items=array_map(fn($n)=>$this->work($n,true),range(0,9));
        $l->expects(self::exactly(2))->method('searchWorks')->willReturnOnConsecutiveCalls(new BibliographicWorkSearchPage($q,$items,$items[9]->cursor($q)),new BibliographicWorkSearchPage($q,$items,null));
        $e->expects(self::never())->method('searchWorkSource');
        $first=$s->search($q);self::assertSame('local',$first->next->phase);self::assertSame('not_checked',$first->sourceState);
        $next=$s->search($q,$first->next);self::assertSame('external',$next->next->phase);self::assertSame(0,$next->next->offset);
    }
    public function testPartialWindowUsesRemainingCapacityAndConsumesRejectedAndDuplicateRecords(): void
    {
        $q=new BibliographicTextSearchQuery('Work');[$s,$l,$e,$matcher]=$this->setupService();$matcher->expects(self::never())->method('workMatchesQuery');$local=$this->work(0,true);
        $l->expects(self::once())->method('searchWorks')->willReturn(new BibliographicWorkSearchPage($q,[$local],null));
        $e->expects(self::once())->method('searchWorkSource')->with($q,0,9)->willReturn(new BibliographicWorkSourcePage($q,0,9,9,[$this->work(0),$this->work(2,false,'/works/OL1W'),$this->work(8)],6,9));
        $g=$s->search($q);self::assertCount(3,$g->items);self::assertSame('incomplete',$g->sourceState);self::assertSame(9,$g->next->offset);self::assertSame(0,$g->retry->offset);self::assertSame(9,$g->retry->capacity);
    }
    public function testExactMappingOnlyHidesCanonicalWorksMatchingTheSameQuery(): void
    {
        $q=new BibliographicTextSearchQuery('Work');[$s,$l,$e,$matcher,$maps]=$this->setupService();
        $l->expects(self::once())->method('searchWorks')->willReturn(new BibliographicWorkSearchPage($q,[],null));
        $e->expects(self::once())->method('searchWorkSource')->willReturn(new BibliographicWorkSourcePage($q,0,10,3,[$this->work(0),$this->work(1),$this->work(2)],0,null));
        $maps->method('findWork')->willReturnCallback(fn($p,$type,$id)=>match($id){'/works/OL1W'=>new WorkId('matching'),'/works/OL2W'=>new WorkId('not-matching'),default=>null});
        $matcher->expects(self::exactly(2))->method('workMatchesQuery')->willReturnCallback(fn($id,$query)=>$id->value()==='matching'&&$query===$q);
        self::assertSame(['/works/OL2W','/works/OL3W'],array_map(fn($v)=>$v->reference()->providerIdentity()->providerRecordId(),$s->search($q)->items));
    }
    public function testCompletelyRejectedAndTransportFailedWindowsRemainDistinctAndRetrySameWindow(): void
    {
        $q=new BibliographicTextSearchQuery('Work');[$s,$l,$e,$matcher]=$this->setupService();$matcher->expects(self::never())->method('workMatchesQuery');$l->expects(self::never())->method('searchWorks');
        $e->expects(self::exactly(2))->method('searchWorkSource')->with($q,20,10)->willReturnCallback(static function()use($q){static $n=0;if($n++===0)throw new BibliographicSearchProviderFailure(ProviderLookupStatus::Unavailable,ProviderFailureReason::Timeout);return new BibliographicWorkSourcePage($q,20,10,10,[],10,30);});
        $failed=$s->search($q,new BookSearchWorkProgress('external',20));self::assertSame('failed',$failed->sourceState);self::assertNull($failed->next);self::assertSame(20,$failed->retry->offset);
        $partial=$s->search($q,$failed->retry);self::assertSame('incomplete',$partial->sourceState);self::assertSame([],$partial->items);self::assertSame(30,$partial->next->offset);self::assertSame(20,$partial->retry->offset);
    }
    public function testUnauthenticatedReadDoesNotReachProviders(): void
    {
        [$s,$l,$e,$matcher]=$this->setupService(new ControllableAuthenticatedUser());$matcher->expects(self::never())->method('workMatchesQuery');$l->expects(self::never())->method('searchWorks');$e->expects(self::never())->method('searchWorkSource');$this->expectException(AuthenticationException::class);$s->search(new BibliographicTextSearchQuery('Work'));
    }
    public function testProgressStrictlyRejectsForeignVersionGroupFieldsAndInvalidWindows(): void
    {
        $q=new BibliographicTextSearchQuery('Work');$good=(new BookSearchWorkProgress('external',10,4,action:'retry'))->data();self::assertSame($good,BookSearchWorkProgress::fromData($good,$q)->data());
        foreach([['v'=>2],['group'=>'authors'],['offset'=>-1],['capacity'=>11],['action'=>'unknown'],['phase'=>'local'],['unknown'=>true]]as$change){try{BookSearchWorkProgress::fromData(array_replace($good,$change),$q);self::fail('Invalid progress accepted');}catch(ValidationException){self::assertTrue(true);}}
    }
}
