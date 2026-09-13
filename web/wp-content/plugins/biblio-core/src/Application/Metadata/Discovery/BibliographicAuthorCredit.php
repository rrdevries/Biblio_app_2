<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

use Biblio\Core\Application\Metadata\Author\AuthorCreditProviderSourceType;
use Biblio\Core\Application\Metadata\Author\AuthorContributorCreditKey;
use Biblio\Core\Application\Metadata\Author\OpenLibraryAuthorId;
use Biblio\Core\Catalog\ContributorPosition;
use Biblio\Core\Catalog\ContributorRole;
use Biblio\Core\Identity\IdentifierConstraints;

/** Trusted provider-normalized Work Author evidence kept inside a snapshot. */
final readonly class BibliographicAuthorCredit
{
    public function __construct(
        private string $observedDisplayName,
        private ContributorRole $role,
        private ContributorPosition $position,
        private AuthorCreditProviderSourceType $sourceType,
        private string $sourceRecordId,
        private ?OpenLibraryAuthorId $openLibraryAuthorId = null
    ) {
        AuthorContributorCreditKey::normalizeObservedName($observedDisplayName);
        IdentifierConstraints::assertValid(
            $sourceRecordId,
            "Provider source record ID"
        );
    }

    public function observedDisplayName(): string { return $this->observedDisplayName; }
    public function role(): ContributorRole { return $this->role; }
    public function position(): ContributorPosition { return $this->position; }
    public function sourceType(): AuthorCreditProviderSourceType { return $this->sourceType; }
    public function sourceRecordId(): string { return $this->sourceRecordId; }
    public function openLibraryAuthorId(): ?OpenLibraryAuthorId
    {
        return $this->openLibraryAuthorId;
    }
}
