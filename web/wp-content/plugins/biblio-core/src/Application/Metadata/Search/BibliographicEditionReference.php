<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Catalog\EditionId;
use InvalidArgumentException;

final readonly class BibliographicEditionReference
{
    private function __construct(
        private BibliographicSearchResultKind $kind,
        private ?EditionId $editionId,
        private ?BibliographicProviderEntityIdentity $providerIdentity
    ) {
        if ($providerIdentity !== null
            && $providerIdentity->entityType() !== BibliographicProviderEntityType::Edition) {
            throw new InvalidArgumentException("Edition reference requires provider Edition identity.");
        }
        if ($kind === BibliographicSearchResultKind::LocalCanonical && $editionId === null) {
            throw new InvalidArgumentException("Canonical Edition reference requires an Edition ID.");
        }
        if ($kind === BibliographicSearchResultKind::ExternalCandidate
            && ($editionId !== null || $providerIdentity === null)) {
            throw new InvalidArgumentException("External Edition reference requires only provider identity.");
        }
    }

    public static function canonical(
        EditionId $editionId,
        ?BibliographicProviderEntityIdentity $providerEvidence = null
    ): self {
        return new self(
            BibliographicSearchResultKind::LocalCanonical,
            $editionId,
            $providerEvidence
        );
    }

    public static function external(BibliographicProviderEntityIdentity $identity): self
    {
        return new self(BibliographicSearchResultKind::ExternalCandidate, null, $identity);
    }

    public function kind(): BibliographicSearchResultKind { return $this->kind; }
    public function editionId(): ?EditionId { return $this->editionId; }
    public function providerIdentity(): ?BibliographicProviderEntityIdentity
    {
        return $this->providerIdentity;
    }

    public function resultId(): string
    {
        $identity = $this->editionId?->value()
            ?? $this->providerIdentity?->stableKey()
            ?? throw new InvalidArgumentException("Edition reference has no strong identity.");

        return "search-edition-" . hash("sha256", "edition\0" . $identity);
    }

    /** @return list<string> */
    public function strongIdentityKeys(): array
    {
        $keys = [];
        if ($this->editionId !== null) {
            $keys[] = "edition\0canonical\0" . $this->editionId->value();
        }
        if ($this->providerIdentity !== null) {
            $keys[] = "edition\0provider\0" . $this->providerIdentity->stableKey();
        }
        return $keys;
    }
}
