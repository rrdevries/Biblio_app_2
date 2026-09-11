<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Catalog\WorkId;
use InvalidArgumentException;

final readonly class BibliographicWorkReference
{
    private function __construct(
        private BibliographicSearchResultKind $kind,
        private ?WorkId $workId,
        private ?BibliographicProviderEntityIdentity $providerIdentity
    ) {
        if ($providerIdentity !== null
            && $providerIdentity->entityType() !== BibliographicProviderEntityType::Work) {
            throw new InvalidArgumentException("Work reference requires provider Work identity.");
        }
        if ($kind === BibliographicSearchResultKind::LocalCanonical && $workId === null) {
            throw new InvalidArgumentException("Canonical Work reference requires a Work ID.");
        }
        if ($kind === BibliographicSearchResultKind::ExternalCandidate
            && ($workId !== null || $providerIdentity === null)) {
            throw new InvalidArgumentException("External Work reference requires only provider identity.");
        }
    }

    public static function canonical(
        WorkId $workId,
        ?BibliographicProviderEntityIdentity $providerEvidence = null
    ): self {
        return new self(
            BibliographicSearchResultKind::LocalCanonical,
            $workId,
            $providerEvidence
        );
    }

    public static function external(BibliographicProviderEntityIdentity $identity): self
    {
        return new self(BibliographicSearchResultKind::ExternalCandidate, null, $identity);
    }

    public function kind(): BibliographicSearchResultKind { return $this->kind; }
    public function workId(): ?WorkId { return $this->workId; }
    public function providerIdentity(): ?BibliographicProviderEntityIdentity
    {
        return $this->providerIdentity;
    }

    public function resultId(): string
    {
        $identity = $this->workId?->value()
            ?? $this->providerIdentity?->stableKey()
            ?? throw new InvalidArgumentException("Work reference has no strong identity.");

        return "search-work-" . hash("sha256", "work\0" . $identity);
    }

    public function cursorContextId(): string
    {
        return hash("sha256", implode("\0", [
            $this->kind->value,
            $this->workId?->value() ?? "",
            $this->providerIdentity?->stableKey() ?? "",
        ]));
    }

    /** @return list<string> */
    public function strongIdentityKeys(): array
    {
        $keys = [];
        if ($this->workId !== null) {
            $keys[] = "work\0canonical\0" . $this->workId->value();
        }
        if ($this->providerIdentity !== null) {
            $keys[] = "work\0provider\0" . $this->providerIdentity->stableKey();
        }
        return $keys;
    }
}
