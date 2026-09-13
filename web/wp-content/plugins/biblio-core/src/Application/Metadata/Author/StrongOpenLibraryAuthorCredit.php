<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Catalog\{ContributorPosition,ContributorRole,WorkId};
use Biblio\Core\Exception\ValidationException;
use DateTimeImmutable;

final readonly class StrongOpenLibraryAuthorCredit
{
    public function __construct(
        private WorkId $workId,
        private ContributorRole $role,
        private ContributorPosition $position,
        private string $observedDisplayName,
        private OpenLibraryAuthorId $providerAuthorId,
        private AuthorCreditProviderSourceType $sourceType,
        private string $sourceRecordId,
        private DateTimeImmutable $observedAt
    ) {
        AuthorContributorCreditKey::normalizeObservedName($observedDisplayName);
        $pattern = $sourceType === AuthorCreditProviderSourceType::Work
            ? '#^/works/OL[0-9]+W$#D'
            : '#^/books/OL[0-9]+M$#D';
        if (preg_match($pattern, $sourceRecordId) !== 1) {
            throw new ValidationException(
                "Open Library contributor source record ID is invalid."
            );
        }
    }

    public function workId(): WorkId { return $this->workId; }
    public function role(): ContributorRole { return $this->role; }
    public function position(): ContributorPosition { return $this->position; }
    public function observedDisplayName(): string
    {
        return $this->observedDisplayName;
    }
    public function providerAuthorId(): OpenLibraryAuthorId
    {
        return $this->providerAuthorId;
    }
    public function sourceType(): AuthorCreditProviderSourceType
    {
        return $this->sourceType;
    }
    public function sourceRecordId(): string { return $this->sourceRecordId; }
    public function observedAt(): DateTimeImmutable { return $this->observedAt; }

    public function sourceIdentity(): AuthorContributorCreditSourceIdentity
    {
        return AuthorContributorCreditSourceIdentity::provider(
            OpenLibraryAuthorId::PROVIDER_KEY,
            $this->sourceType->value,
            $this->sourceRecordId,
            $this->position->value()
        );
    }
}
