<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Catalog\AuthorId;
use Biblio\Core\Exception\ValidationException;
use Throwable;

final readonly class BibliographicAuthorSelectorCodec
{
    private const int VERSION = 1;
    private const string TYPE = "author_selector";
    private const string PROVIDER_OPEN_LIBRARY = "open_library";
    private const int MAXIMUM_ENCODED_LENGTH = 2048;

    public function __construct(private string $secret)
    {
        if (strlen($secret) < 32) {
            throw new ValidationException(
                "Bibliographic Author selector secret must contain at least 32 bytes."
            );
        }
    }

    public function encode(BibliographicAuthorReference $reference): string
    {
        $authorId = $reference->authorId()?->value();
        $providerIdentity = $reference->providerIdentity();
        if ($providerIdentity !== null) {
            $this->assertSupportedProviderIdentity($providerIdentity);
        }

        if ($authorId !== null) {
            $form = $providerIdentity === null ? "canonical" : "composite";
        } elseif ($providerIdentity !== null) {
            $form = "provider";
        } else {
            throw new ValidationException("Invalid bibliographic Author selector.");
        }
        $json = json_encode([
            "v" => self::VERSION,
            "type" => self::TYPE,
            "form" => $form,
            "author_id" => $authorId,
            "provider" => $providerIdentity?->providerKey(),
            "provider_author_id" => $providerIdentity?->providerRecordId(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $payload = $this->base64UrlEncode($json);
        $signature = $this->base64UrlEncode(
            hash_hmac("sha256", $payload, $this->secret, true)
        );

        return $payload . "." . $signature;
    }

    public function decode(string $encoded): BibliographicAuthorReference
    {
        try {
            if ($encoded === "" || strlen($encoded) > self::MAXIMUM_ENCODED_LENGTH) {
                throw new ValidationException("Invalid bibliographic Author selector.");
            }
            $parts = explode(".", $encoded);
            if (count($parts) !== 2) {
                throw new ValidationException("Invalid bibliographic Author selector.");
            }
            [$payload, $signature] = $parts;
            $expected = $this->base64UrlEncode(
                hash_hmac("sha256", $payload, $this->secret, true)
            );
            if (!hash_equals($expected, $signature)) {
                throw new ValidationException("Invalid bibliographic Author selector.");
            }

            $decoded = json_decode(
                $this->base64UrlDecode($payload),
                true,
                8,
                JSON_THROW_ON_ERROR
            );
            if (!is_array($decoded)
                || array_keys($decoded) !== [
                    "v",
                    "type",
                    "form",
                    "author_id",
                    "provider",
                    "provider_author_id",
                ]
                || $decoded["v"] !== self::VERSION
                || $decoded["type"] !== self::TYPE
                || !is_string($decoded["form"])
                || !($decoded["author_id"] === null || is_string($decoded["author_id"]))
                || !($decoded["provider"] === null || is_string($decoded["provider"]))
                || !($decoded["provider_author_id"] === null
                    || is_string($decoded["provider_author_id"]))) {
                throw new ValidationException("Invalid bibliographic Author selector.");
            }

            return $this->reference(
                $decoded["form"],
                $decoded["author_id"],
                $decoded["provider"],
                $decoded["provider_author_id"]
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ValidationException("Invalid bibliographic Author selector.");
        }
    }

    private function reference(
        string $form,
        ?string $authorId,
        ?string $provider,
        ?string $providerAuthorId
    ): BibliographicAuthorReference {
        if ($form === "canonical" && $authorId !== null
            && $provider === null && $providerAuthorId === null) {
            return BibliographicAuthorReference::canonical(new AuthorId($authorId));
        }
        if (($form === "provider" || $form === "composite")
            && ($form === "provider") === ($authorId === null)
            && $provider !== null && $providerAuthorId !== null) {
            $identity = BibliographicProviderEntityIdentity::author(
                $provider,
                $providerAuthorId
            );
            $this->assertSupportedProviderIdentity($identity);
            return $form === "provider"
                ? BibliographicAuthorReference::external($identity)
                : BibliographicAuthorReference::canonical(new AuthorId($authorId), $identity);
        }

        throw new ValidationException("Invalid bibliographic Author selector.");
    }

    private function assertSupportedProviderIdentity(
        BibliographicProviderEntityIdentity $identity
    ): void {
        if ($identity->entityType() !== BibliographicProviderEntityType::Author
            || $identity->providerKey() !== self::PROVIDER_OPEN_LIBRARY
            || preg_match(
                '#^/authors/OL[0-9]+A$#D',
                $identity->providerRecordId()
            ) !== 1) {
            throw new ValidationException("Invalid bibliographic Author selector.");
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), "+/", "-_"), "=");
    }

    private function base64UrlDecode(string $value): string
    {
        if ($value === "" || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new ValidationException("Invalid bibliographic Author selector.");
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(
            strtr($value, "-_", "+/") . str_repeat("=", $padding),
            true
        );
        if ($decoded === false) {
            throw new ValidationException("Invalid bibliographic Author selector.");
        }
        return $decoded;
    }
}
