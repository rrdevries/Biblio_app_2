<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicProviderEntityIdentity
{
    private function __construct(
        private BibliographicProviderEntityType $entityType,
        private string $providerKey,
        private string $providerRecordId
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $providerKey) !== 1) {
            throw new ValidationException("Invalid bibliographic search provider key.");
        }
        if (
            !mb_check_encoding($providerRecordId, "UTF-8")
            || trim($providerRecordId) !== $providerRecordId
            || $providerRecordId === ""
            || mb_strlen($providerRecordId, "UTF-8") > 191
        ) {
            throw new ValidationException("Invalid bibliographic provider entity ID.");
        }
    }

    public static function author(string $providerKey, string $providerRecordId): self
    {
        return new self(BibliographicProviderEntityType::Author, $providerKey, $providerRecordId);
    }

    public static function work(string $providerKey, string $providerRecordId): self
    {
        return new self(BibliographicProviderEntityType::Work, $providerKey, $providerRecordId);
    }

    public function entityType(): BibliographicProviderEntityType { return $this->entityType; }
    public function providerKey(): string { return $this->providerKey; }
    public function providerRecordId(): string { return $this->providerRecordId; }

    public function stableKey(): string
    {
        return $this->entityType->value . "\0" . $this->providerKey
            . "\0" . $this->providerRecordId;
    }
}
