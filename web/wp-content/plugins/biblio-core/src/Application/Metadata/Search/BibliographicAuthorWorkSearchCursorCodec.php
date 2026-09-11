<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;
use Throwable;

final readonly class BibliographicAuthorWorkSearchCursorCodec
{
    private const int VERSION = 1;
    private const int MAXIMUM_ENCODED_LENGTH = 1024;

    public function __construct(private string $secret)
    {
        if (strlen($secret) < 32) {
            throw new ValidationException(
                "Works-by-Author cursor secret must contain at least 32 bytes."
            );
        }
    }

    public function encode(BibliographicAuthorWorkSearchCursor $cursor): string
    {
        $json = json_encode([
            "v" => self::VERSION,
            "author" => $cursor->authorContextId(),
            "lane" => $cursor->lane()->value,
            "next_offset" => $cursor->nextOffset(),
        ], JSON_THROW_ON_ERROR);
        $payload = $this->base64UrlEncode($json);
        $signature = $this->base64UrlEncode(hash_hmac("sha256", $payload, $this->secret, true));
        return $payload . "." . $signature;
    }

    public function decode(string $encoded): BibliographicAuthorWorkSearchCursor
    {
        try {
            if ($encoded === "" || strlen($encoded) > self::MAXIMUM_ENCODED_LENGTH) {
                throw new ValidationException("Invalid Works-by-Author cursor.");
            }
            $parts = explode(".", $encoded);
            if (count($parts) !== 2) {
                throw new ValidationException("Invalid Works-by-Author cursor.");
            }
            [$payload, $signature] = $parts;
            $expected = $this->base64UrlEncode(hash_hmac("sha256", $payload, $this->secret, true));
            if (!hash_equals($expected, $signature)) {
                throw new ValidationException("Invalid Works-by-Author cursor.");
            }
            $decoded = json_decode($this->base64UrlDecode($payload), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)
                || array_keys($decoded) !== ["v", "author", "lane", "next_offset"]
                || $decoded["v"] !== self::VERSION
                || !is_string($decoded["author"])
                || !is_string($decoded["lane"])
                || !is_int($decoded["next_offset"])) {
                throw new ValidationException("Invalid Works-by-Author cursor.");
            }
            return new BibliographicAuthorWorkSearchCursor(
                $decoded["author"],
                BibliographicAuthorWorkSearchLane::from($decoded["lane"]),
                $decoded["next_offset"]
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ValidationException("Invalid Works-by-Author cursor.");
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), "+/", "-_"), "=");
    }

    private function base64UrlDecode(string $value): string
    {
        if ($value === "" || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new ValidationException("Invalid Works-by-Author cursor.");
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, "-_", "+/") . str_repeat("=", $padding), true);
        if ($decoded === false) {
            throw new ValidationException("Invalid Works-by-Author cursor.");
        }
        return $decoded;
    }
}
