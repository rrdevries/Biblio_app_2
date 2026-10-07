<?php

declare(strict_types=1);
namespace Biblio\Core\Application\Metadata\GlobalSearch;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Exception\ValidationException;
use Closure;
use Throwable;

/** Standalone read authority. No raw WordPress session token leaves the server. */
final readonly class BookSearchTokenCodec
{
    public function __construct(private string $secret, private AuthenticatedUser $actor,
        private MetadataClock $clock, private Closure $sessionFingerprint)
    {
        if (strlen($secret) < 32) { throw new ValidationException('Invalid search signing key.'); }
    }

    public function context(string $query): array
    {
        $now = $this->clock->now()->getTimestamp();
        return ['id'=>bin2hex(random_bytes(16)), 'query'=>$query, 'expires'=>$now+1800];
    }

    public function encode(string $type, array $data, array $context): string
    {
        $payload = ['v'=>1, 'type'=>$type, 'actor'=>$this->actor->requireUserId()->value(),
            'session'=>$this->session(), 'context'=>$context, 'data'=>$data];
        $body = $this->base64(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $body.'.'.$this->base64(hash_hmac('sha256', $body, $this->secret, true));
    }

    public function decode(string $type, mixed $token, ?array $context = null): array
    {
        try {
            if (!is_string($token) || strlen($token) > 65536) { throw new \RuntimeException(); }
            $parts = explode('.', $token);
            if (count($parts) !== 2 || !hash_equals($this->base64(hash_hmac('sha256', $parts[0], $this->secret, true)), $parts[1])) { throw new \RuntimeException(); }
            $raw = base64_decode(strtr($parts[0], '-_', '+/'), true);
            $p = json_decode($raw === false ? '' : $raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($p) || ($p['v']??null)!==1 || ($p['type']??null)!==$type
                || ($p['actor']??null)!==$this->actor->requireUserId()->value()
                || !is_string($p['session']??null) || !hash_equals($this->session(), $p['session'])
                || !is_array($p['context']??null) || !is_array($p['data']??null)
                || !is_int($p['context']['expires']??null)
                || $p['context']['expires'] <= $this->clock->now()->getTimestamp()
                || ($context !== null && $p['context'] !== $context)) { throw new \RuntimeException(); }
            return $p;
        } catch (Throwable) { throw new BookSearchContextUnavailable('Search context is invalid or expired. Start a new search.'); }
    }

    private function session(): string
    {
        $session = ($this->sessionFingerprint)();
        if (!is_string($session) || $session === '') { throw new ValidationException('An authenticated search session is required.'); }
        return hash_hmac('sha256', $session, $this->secret);
    }
    private function base64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
}
