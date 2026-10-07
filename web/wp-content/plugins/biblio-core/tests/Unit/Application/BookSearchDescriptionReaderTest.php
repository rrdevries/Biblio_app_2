<?php

declare(strict_types=1);
namespace Biblio\Core\Tests\Unit\Application;
use Biblio\Core\Infrastructure\Metadata\ProviderBookSearchDescriptionReader;
use Biblio\Core\Infrastructure\Metadata\OpenLibrary\OpenLibraryConfiguration;
use Biblio\Core\Infrastructure\Metadata\GoogleBooks\GoogleBooksConfiguration;
use Biblio\Core\Application\Metadata\{ProviderHttpClient,ProviderHttpRequest,ProviderHttpResult,ProviderHttpResponse,MetadataClock};
use PHPUnit\Framework\TestCase;
final class BookSearchDescriptionReaderTest extends TestCase
{
    public function testExactRecordHtmlPlaintextUnknownLanguageBoundsAndFailureAreDistinct(): void
    {
        $http=new class implements ProviderHttpClient {public int $calls=0;public string $body='{}';public function get(ProviderHttpRequest $r):ProviderHttpResult{$this->calls++;return ProviderHttpResult::response(new ProviderHttpResponse(200,$this->body));}};
        $clock=new class implements MetadataClock {public function now():\DateTimeImmutable{return new \DateTimeImmutable('2026-10-07T00:00:00Z');}};
        $reader=new ProviderBookSearchDescriptionReader($http,$clock,new OpenLibraryConfiguration('Biblio','2.001','test@example.test'),new GoogleBooksConfiguration(null));
        $source=['provider_key'=>'google_books','record_id'=>'volume_1','scope'=>'edition'];
        $http->body=json_encode(['id'=>'volume_1','volumeInfo'=>['language'=>'nl','description'=>'<p>Hello &amp; world</p><p>Second</p><script>steal()</script>']]);
        $result=$reader->read($source);self::assertSame('available',$result['state']);self::assertSame("Hello & world\n\nSecond",$result['text']);self::assertNull($result['language']);
        $http->body=json_encode(['id'=>'different','volumeInfo'=>['description'=>'Wrong record']]);self::assertSame('failure',$reader->read($source)['state']);
        $http->body=json_encode(['id'=>'volume_1','volumeInfo'=>[]]);self::assertSame('unavailable',$reader->read($source)['state']);
        $http->body='invalid';self::assertSame('failure',$reader->read($source)['state']);
        $http->body=json_encode(['id'=>'volume_1','volumeInfo'=>['description'=>str_repeat('é',20000)]]);$result=$reader->read($source);self::assertTrue($result['truncated']);self::assertLessThanOrEqual(32768,strlen($result['text']));self::assertTrue(mb_check_encoding($result['text'],'UTF-8'));
        $before=$http->calls;self::assertSame('unavailable',$reader->read(['provider_key'=>'open_library','record_id'=>'https://evil.test','scope'=>'work'])['state']);self::assertSame($before,$http->calls);
        $disabled=new ProviderBookSearchDescriptionReader($http,$clock,null,null);self::assertSame('failure',$disabled->read(['provider_key'=>'open_library','record_id'=>'/works/OL1W','scope'=>'work'])['state']);self::assertSame($before,$http->calls);
    }
}
