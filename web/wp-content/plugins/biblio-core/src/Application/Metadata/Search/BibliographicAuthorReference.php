<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Catalog\AuthorId;
use InvalidArgumentException;

final readonly class BibliographicAuthorReference
{
    private function __construct(
        private BibliographicSearchResultKind $kind,
        private ?AuthorId $authorId,
        private ?BibliographicProviderEntityIdentity $providerIdentity
    ) {
        if ($providerIdentity !== null
            && $providerIdentity->entityType() !== BibliographicProviderEntityType::Author) {
            throw new InvalidArgumentException("Author reference requires provider Author identity.");
        }
        if ($kind === BibliographicSearchResultKind::LocalCanonical && $authorId === null) {
            throw new InvalidArgumentException("Canonical Author reference requires an Author ID.");
        }
        if ($kind === BibliographicSearchResultKind::ExternalCandidate
            && ($authorId !== null || $providerIdentity === null)) {
            throw new InvalidArgumentException("External Author reference requires only provider identity.");
        }
    }

    public static function canonical(
        AuthorId $authorId,
        ?BibliographicProviderEntityIdentity $providerEvidence = null
    ): self {
        return new self(
            BibliographicSearchResultKind::LocalCanonical,
            $authorId,
            $providerEvidence
        );
    }

    public static function external(BibliographicProviderEntityIdentity $identity): self
    {
        return new self(BibliographicSearchResultKind::ExternalCandidate, null, $identity);
    }

    public function kind(): BibliographicSearchResultKind { return $this->kind; }
    public function authorId(): ?AuthorId { return $this->authorId; }
    public function providerIdentity(): ?BibliographicProviderEntityIdentity
    {
        return $this->providerIdentity;
    }

    public function resultId(): string
    {
        $identity = $this->authorId?->value()
            ?? $this->providerIdentity?->stableKey()
            ?? throw new InvalidArgumentException("Author reference has no strong identity.");

        return "search-author-" . hash("sha256", "author\0" . $identity);
    }

    /** @return list<string> */
    public function strongIdentityKeys(): array
    {
        $keys = [];
        if ($this->authorId !== null) {
            $keys[] = "author\0canonical\0" . $this->authorId->value();
        }
        if ($this->providerIdentity !== null) {
            $keys[] = "author\0provider\0" . $this->providerIdentity->stableKey();
        }
        return $keys;
    }
}
