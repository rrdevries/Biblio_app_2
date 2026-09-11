<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;
use Throwable;

final readonly class BibliographicSearchCursorCodec
{
    private const int VERSION = 1;
    private const int MAXIMUM_ENCODED_LENGTH = 2048;

    public function __construct(private string $secret)
    {
        if (strlen($secret) < 32) {
            throw new ValidationException(
                "Bibliographic search cursor secret must contain at least 32 bytes."
            );
        }
    }

    public function encode(BibliographicSearchCursor $cursor): string
    {
        $json = json_encode([
            "v" => self::VERSION,
            "q" => $cursor->query()->value(),
            "group" => $cursor->group()->value,
            "kind" => $cursor->kind()->value,
            "order" => $cursor->presentationOrder(),
            "result_id" => $cursor->resultId(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $payload = $this->base64UrlEncode($json);
        $signature = $this->base64UrlEncode(
            hash_hmac("sha256", $payload, $this->secret, true)
        );

        return $payload . "." . $signature;
    }

    public function decode(string $encoded): BibliographicSearchCursor
    {
        try {
            if ($encoded === "" || strlen($encoded) > self::MAXIMUM_ENCODED_LENGTH) {
                throw new ValidationException("Invalid bibliographic search cursor.");
            }
            $parts = explode(".", $encoded);
            if (count($parts) !== 2) {
                throw new ValidationException("Invalid bibliographic search cursor.");
            }
            [$payload, $signature] = $parts;
            $expected = $this->base64UrlEncode(
                hash_hmac("sha256", $payload, $this->secret, true)
            );
            if (!hash_equals($expected, $signature)) {
                throw new ValidationException("Invalid bibliographic search cursor.");
            }
            $json = $this->base64UrlDecode($payload);
            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($payload)
                || array_keys($payload) !== ["v", "q", "group", "kind", "order", "result_id"]
                || $payload["v"] !== self::VERSION
                || !is_string($payload["q"])
                || !is_string($payload["group"])
                || !is_string($payload["kind"])
                || !is_int($payload["order"])
                || !is_string($payload["result_id"])) {
                throw new ValidationException("Invalid bibliographic search cursor.");
            }

            return new BibliographicSearchCursor(
                new BibliographicTextSearchQuery($payload["q"]),
                BibliographicSearchGroup::from($payload["group"]),
                BibliographicSearchResultKind::from($payload["kind"]),
                $payload["order"],
                $payload["result_id"]
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ValidationException("Invalid bibliographic search cursor.");
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), "+/", "-_"), "=");
    }

    private function base64UrlDecode(string $value): string
    {
        if ($value === "" || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new ValidationException("Invalid bibliographic search cursor.");
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(
            strtr($value, "-_", "+/") . str_repeat("=", $padding),
            true
        );
        if ($decoded === false) {
            throw new ValidationException("Invalid bibliographic search cursor.");
        }
        return $decoded;
    }
}
