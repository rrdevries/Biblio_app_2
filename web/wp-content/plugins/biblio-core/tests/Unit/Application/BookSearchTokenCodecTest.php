<?php

declare(strict_types=1);
namespace Biblio\Core\Tests\Unit\Application;
use Biblio\Core\Application\Metadata\GlobalSearch\{BookSearchTokenCodec,BookSearchContextUnavailable};
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Identity\UserId;
use PHPUnit\Framework\TestCase;

final class BookSearchTokenCodecTest extends TestCase
{
    public function testAuthorityRejectsOtherActorNewSessionDifferentContextTamperingAndExpiryBoundary(): void
    {
        $actor=new class implements AuthenticatedUser { public string $id='actor-1';public function requireUserId():UserId{return new UserId($this->id);} };
        $clock=new class implements MetadataClock { public int $timestamp=1791290000;public function now():\DateTimeImmutable{return (new \DateTimeImmutable())->setTimestamp($this->timestamp);} };
        $session='session-one';$codec=new BookSearchTokenCodec(str_repeat('k',32),$actor,$clock,static function() use (&$session):string{return $session;});
        $ctx=$codec->context('Boek');$token=$codec->encode('selection',['scope'=>'work','record'=>['result_id'=>'proof']],$ctx);
        self::assertSame('proof',$codec->decode('selection',$token,$ctx)['data']['record']['result_id']);
        $cases=[static function()use($actor){$actor->id='actor-2';},static function()use(&$session){$session='session-two';},static function()use($clock,$ctx){$clock->timestamp=$ctx['expires'];}];
        foreach($cases as $change){$actor->id='actor-1';$session='session-one';$clock->timestamp=1791290000;$change();try{$codec->decode('selection',$token,$ctx);self::fail('Stale authority was accepted.');}catch(BookSearchContextUnavailable){self::assertTrue(true);}}
        $actor->id='actor-1';$session='session-one';$clock->timestamp=1791290000;
        foreach([['selection',$token,$codec->context('Another query')],['context',$token,$ctx],['selection',substr($token,0,-1).'!', $ctx]]as[$type,$value,$context]){try{$codec->decode($type,$value,$context);self::fail('Invalid authority was accepted.');}catch(BookSearchContextUnavailable){self::assertTrue(true);}}
    }
}
