<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Catalog\{ContributorPosition, ContributorRole, WorkId};
use DateTimeImmutable;

final readonly class ManualAuthorCredit implements
    NameOnlyAuthorMaterializationCredit
{
    public function __construct(
        private WorkId $workId,
        private ManualAuthorAttempt $attempt,
        private DateTimeImmutable $observedAt
    ) {
    }

    public function workId(): WorkId { return $this->workId; }
    public function role(): ContributorRole { return $this->attempt->role(); }
    public function position(): ContributorPosition
    {
        return $this->attempt->position();
    }
    public function observedDisplayName(): string
    {
        return $this->attempt->displayName();
    }
    public function sourceIdentity(): AuthorContributorCreditSourceIdentity
    {
        return AuthorContributorCreditSourceIdentity::userObservation(
            $this->attempt->observationId()
        );
    }
    public function evidence(
        AuthorContributorCreditId $creditId
    ): AuthorCreditEvidence {
        return AuthorCreditEvidence::userObservation(
            $creditId,
            $this->attempt->observationId(),
            $this->attempt->displayName(),
            $this->attempt->role(),
            $this->attempt->position(),
            $this->observedAt
        );
    }
}
