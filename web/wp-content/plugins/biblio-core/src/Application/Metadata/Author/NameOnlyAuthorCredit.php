<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Catalog\{ContributorPosition,ContributorRole,WorkId};
use DateTimeImmutable;

final readonly class NameOnlyAuthorCredit implements AuthorMaterializationCredit
{
    public function __construct(
        private WorkId $workId,
        private ContributorRole $role,
        private ContributorPosition $position,
        private string $observedDisplayName,
        private string $provider,
        private AuthorCreditProviderSourceType $sourceType,
        private string $sourceRecordId,
        private DateTimeImmutable $observedAt
    ) {
        AuthorContributorCreditKey::normalizeObservedName($observedDisplayName);
        $this->sourceIdentity();
    }

    public function workId(): WorkId { return $this->workId; }
    public function role(): ContributorRole { return $this->role; }
    public function position(): ContributorPosition { return $this->position; }
    public function observedDisplayName(): string
    {
        return $this->observedDisplayName;
    }
    public function provider(): string { return $this->provider; }
    public function sourceType(): AuthorCreditProviderSourceType
    {
        return $this->sourceType;
    }
    public function sourceRecordId(): string { return $this->sourceRecordId; }
    public function observedAt(): DateTimeImmutable { return $this->observedAt; }

    public function sourceIdentity(): AuthorContributorCreditSourceIdentity
    {
        return AuthorContributorCreditSourceIdentity::provider(
            $this->provider,
            $this->sourceType->value,
            $this->sourceRecordId,
            $this->position->value()
        );
    }

    public function evidence(
        AuthorContributorCreditId $creditId
    ): AuthorCreditEvidence {
        return AuthorCreditEvidence::provider(
            $creditId,
            $this->provider,
            $this->sourceType->value,
            $this->sourceRecordId,
            null,
            $this->observedDisplayName,
            $this->role,
            $this->position,
            $this->observedAt
        );
    }
}
