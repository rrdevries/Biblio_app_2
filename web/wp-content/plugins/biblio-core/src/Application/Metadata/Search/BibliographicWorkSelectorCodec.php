<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderIdentityRepository;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Throwable;

final readonly class BibliographicWorkSelectorCodec
{
    private const int VERSION = 1;
    private const string TYPE = "work_selector";
    private const string PROVIDER_OPEN_LIBRARY = "open_library";
    private const int MAXIMUM_ENCODED_LENGTH = 2048;

    public function __construct(
        private string $secret,
        private ?BibliographicProviderIdentityRepository $providerIdentities = null
    ) {
        if (strlen($secret) < 32) {
            throw new ValidationException(
                "Bibliographic Work selector secret must contain at least 32 bytes."
            );
        }
    }

    public function encode(BibliographicWorkReference $reference): string
    {
        $workId = $reference->workId()?->value();
        $providerIdentity = $reference->providerIdentity();
        if ($providerIdentity !== null) {
            $this->assertSupportedProviderIdentity($providerIdentity);
        }

        if ($workId !== null && $providerIdentity === null) {
            $payload = [
                "v" => self::VERSION,
                "type" => self::TYPE,
                "form" => "canonical",
                "work_id" => $workId,
            ];
        } elseif ($workId === null && $providerIdentity !== null) {
            $payload = [
                "v" => self::VERSION,
                "type" => self::TYPE,
                "form" => "provider",
                "provider" => $providerIdentity->providerKey(),
                "provider_work_id" => $providerIdentity->providerRecordId(),
            ];
        } else {
            $this->assertCurrentMapping(new WorkId($workId), $providerIdentity);
            $payload = [
                "v" => self::VERSION,
                "type" => self::TYPE,
                "form" => "composite",
                "work_id" => $workId,
                "provider" => $providerIdentity->providerKey(),
                "provider_work_id" => $providerIdentity->providerRecordId(),
            ];
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $encodedPayload = $this->base64UrlEncode($json);
        $signature = $this->base64UrlEncode(
            hash_hmac("sha256", $encodedPayload, $this->secret, true)
        );

        return $encodedPayload . "." . $signature;
    }

    public function decode(string $encoded): BibliographicWorkReference
    {
        try {
            if ($encoded === "" || strlen($encoded) > self::MAXIMUM_ENCODED_LENGTH) {
                throw new ValidationException("Invalid bibliographic Work selector.");
            }
            $parts = explode(".", $encoded);
            if (count($parts) !== 2) {
                throw new ValidationException("Invalid bibliographic Work selector.");
            }
            [$payload, $signature] = $parts;
            $expected = $this->base64UrlEncode(
                hash_hmac("sha256", $payload, $this->secret, true)
            );
            if (!hash_equals($expected, $signature)) {
                throw new ValidationException("Invalid bibliographic Work selector.");
            }

            $decoded = json_decode(
                $this->base64UrlDecode($payload),
                true,
                8,
                JSON_THROW_ON_ERROR
            );
            if (!is_array($decoded)
                || !isset($decoded["v"], $decoded["type"], $decoded["form"])
                || $decoded["v"] !== self::VERSION
                || $decoded["type"] !== self::TYPE
                || !is_string($decoded["form"])) {
                throw new ValidationException("Invalid bibliographic Work selector.");
            }

            return $this->reference($decoded);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ValidationException("Invalid bibliographic Work selector.");
        }
    }

    /** @param array<mixed> $payload */
    private function reference(array $payload): BibliographicWorkReference
    {
        if ($payload["form"] === "canonical"
            && array_keys($payload) === ["v", "type", "form", "work_id"]
            && is_string($payload["work_id"])) {
            return BibliographicWorkReference::canonical(new WorkId($payload["work_id"]));
        }

        if ($payload["form"] === "provider"
            && array_keys($payload) === [
                "v", "type", "form", "provider", "provider_work_id",
            ]
            && is_string($payload["provider"])
            && is_string($payload["provider_work_id"])) {
            $identity = BibliographicProviderEntityIdentity::work(
                $payload["provider"],
                $payload["provider_work_id"]
            );
            $this->assertSupportedProviderIdentity($identity);
            return BibliographicWorkReference::external($identity);
        }

        if ($payload["form"] === "composite"
            && array_keys($payload) === [
                "v", "type", "form", "work_id", "provider", "provider_work_id",
            ]
            && is_string($payload["work_id"])
            && is_string($payload["provider"])
            && is_string($payload["provider_work_id"])) {
            $workId = new WorkId($payload["work_id"]);
            $identity = BibliographicProviderEntityIdentity::work(
                $payload["provider"],
                $payload["provider_work_id"]
            );
            $this->assertSupportedProviderIdentity($identity);
            $this->assertCurrentMapping($workId, $identity);
            return BibliographicWorkReference::canonical($workId, $identity);
        }

        throw new ValidationException("Invalid bibliographic Work selector.");
    }

    private function assertCurrentMapping(
        WorkId $workId,
        BibliographicProviderEntityIdentity $identity
    ): void {
        $mapped = $this->providerIdentities?->findWork(
            $identity->providerKey(),
            "work",
            $identity->providerRecordId()
        );
        if ($mapped === null || !$mapped->equals($workId)) {
            throw new ValidationException("Invalid bibliographic Work selector.");
        }
    }

    private function assertSupportedProviderIdentity(
        BibliographicProviderEntityIdentity $identity
    ): void {
        if ($identity->entityType() !== BibliographicProviderEntityType::Work
            || $identity->providerKey() !== self::PROVIDER_OPEN_LIBRARY
            || preg_match(
                '#^/works/OL[0-9]+W$#D',
                $identity->providerRecordId()
            ) !== 1) {
            throw new ValidationException("Invalid bibliographic Work selector.");
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), "+/", "-_"), "=");
    }

    private function base64UrlDecode(string $value): string
    {
        if ($value === "" || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new ValidationException("Invalid bibliographic Work selector.");
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(
            strtr($value, "-_", "+/") . str_repeat("=", $padding),
            true
        );
        if ($decoded === false) {
            throw new ValidationException("Invalid bibliographic Work selector.");
        }
        return $decoded;
    }
}
