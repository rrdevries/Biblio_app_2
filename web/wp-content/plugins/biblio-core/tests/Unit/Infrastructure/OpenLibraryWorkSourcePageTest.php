<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Metadata\{ProviderHttpClient,ProviderHttpRequest,ProviderHttpResponse,ProviderHttpResult};
use Biblio\Core\Application\Metadata\Search\{BibliographicSearchProviderFailure,BibliographicTextSearchQuery};
use Biblio\Core\Infrastructure\Metadata\OpenLibrary\{OpenLibraryBibliographicSearchProvider,OpenLibraryConfiguration};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenLibraryWorkSourcePageTest extends TestCase
{
    #[DataProvider('badPositions')]
    public function testInvalidRecordDoesNotHideValidRecordsAndConsumesOriginalPositions(int $bad): void
    {
        $docs=[];
        for($i=0;$i<10;$i++) $docs[]=['key'=>$i===$bad?'/works/OL99M':'/works/OL'.($i+1).'W','title'=>'Work '.$i];
        [$provider,$http]=$this->provider(['numFound'=>20,'start'=>0,'docs'=>$docs]);
        $page=$provider->searchWorkSource(new BibliographicTextSearchQuery('work'),0,10);
        self::assertCount(9,$page->items());
        self::assertSame(10,$page->consumedCount());
        self::assertSame(1,$page->rejectedCount());
        self::assertSame(10,$page->nextOffset());
        self::assertSame(array_values(array_diff(range(0,9),[$bad])),array_map(fn($w)=>$w->presentationOrder(),$page->items()));
        self::assertCount(1,$http->requests);
    }
    public static function badPositions(): array { return [[0],[4],[9]]; }

    public function testCompletelyRejectedWindowCanContinueWithoutInventingResult(): void
    {
        [$provider]=$this->provider(['numFound'=>5,'start'=>2,'docs'=>[['key'=>'OL1M','title'=>'bad'],['title'=>'missing identity']]]);
        $page=$provider->searchWorkSource(new BibliographicTextSearchQuery('work'),2,2);
        self::assertSame([],$page->items());self::assertSame(2,$page->rejectedCount());self::assertSame(4,$page->nextOffset());
    }
    public function testRejectedFinalWindowIsNotACompleteMiss(): void
    {
        [$provider]=$this->provider(['numFound'=>1,'start'=>0,'docs'=>[['key'=>'OL1M','title'=>'bad']]]);
        $page=$provider->searchWorkSource(new BibliographicTextSearchQuery('work'),0,10);
        self::assertNull($page->nextOffset());self::assertSame(1,$page->rejectedCount());
    }
    public function testNormalMissAndOptionalMissingAuthors(): void
    {
        [$provider]=$this->provider(['numFound'=>0,'start'=>0,'docs'=>[]]);
        $page=$provider->searchWorkSource(new BibliographicTextSearchQuery('work'),0,10);
        self::assertSame(0,$page->rejectedCount());self::assertNull($page->nextOffset());
        [$provider]=$this->provider(['numFound'=>1,'start'=>0,'docs'=>[['key'=>'OL1W','title'=>'work']]]);
        self::assertSame([],$provider->searchWorkSource(new BibliographicTextSearchQuery('work'),0,10)->items()[0]->authors());
    }
    #[DataProvider('badEnvelopes')]
    public function testUntrustedEnvelopeIsAWholeSourceFailure(array $payload): void
    {
        [$provider]=$this->provider($payload);$this->expectException(BibliographicSearchProviderFailure::class);
        $provider->searchWorkSource(new BibliographicTextSearchQuery('work'),0,1);
    }
    public static function badEnvelopes(): array { return [
        [['numFound'=>1,'start'=>1,'docs'=>[['key'=>'OL1W','title'=>'work']]]],
        [['numFound'=>1,'start'=>0,'docs'=>[]]],
        [['numFound'=>0,'start'=>0,'docs'=>[['key'=>'OL1W','title'=>'work']]]],
        [['numFound'=>2,'start'=>0,'docs'=>[['key'=>'OL1W','title'=>'one'],['key'=>'OL2W','title'=>'two']]]],
        [['numFound'=>-1,'start'=>0,'docs'=>[]]],
    ]; }
    public function testIndividualMalformedTitlesListsAndDocumentsDoNotPoisonOtherRecords(): void
    {
        [$provider]=$this->provider(['numFound'=>5,'start'=>0,'docs'=>[
            ['key'=>'OL1W','title'=>'good'],['key'=>'OL2W','title'=>' '],
            ['key'=>'OL3W','title'=>'bad authors','author_name'=>[5]],null,['key'=>'OL5W','title'=>'last'],
        ]]);
        $page=$provider->searchWorkSource(new BibliographicTextSearchQuery('work'),0,10);
        self::assertSame(3,$page->rejectedCount());self::assertSame([0,4],array_map(fn($w)=>$w->presentationOrder(),$page->items()));
    }
    public function testEnvelopeBeyondSupportedSourcePositionFailsWithoutExposingTypedConstructionError(): void
    {
        [$provider]=$this->provider(['numFound'=>1000002,'start'=>1000000,'docs'=>[['key'=>'OL1W','title'=>'one'],['key'=>'OL2W','title'=>'two']]]);
        $this->expectException(BibliographicSearchProviderFailure::class);
        $provider->searchWorkSource(new BibliographicTextSearchQuery('work'),1000000,2);
    }
    public function testLegacyMethodStillRejectsWholeMalformedPage(): void
    {
        [$provider]=$this->provider(['numFound'=>2,'start'=>0,'docs'=>[['key'=>'OL1W','title'=>'good'],['key'=>'OL2M','title'=>'bad']]]);
        $this->expectException(BibliographicSearchProviderFailure::class);$provider->searchWorks(new BibliographicTextSearchQuery('work'));
    }
    private function provider(array $payload): array
    {
        $http=new class(json_encode($payload,JSON_THROW_ON_ERROR)) implements ProviderHttpClient {
            public array $requests=[];public function __construct(private string $json){}
            public function get(ProviderHttpRequest $request): ProviderHttpResult { $this->requests[]=$request;return ProviderHttpResult::response(new ProviderHttpResponse(200,$this->json)); }
        };
        return [new OpenLibraryBibliographicSearchProvider($http,new OpenLibraryConfiguration('BiblioSourceTest','1.0.0','source-test@example.invalid')),$http];
    }
}
