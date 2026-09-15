<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Catalog\{AuthorId, ContributorPosition, ContributorRole, WorkId};
use Biblio\Core\Identity\IdentifierConstraints;
use DateTimeImmutable;

/** A reviewed migration occurrence linked to an exact canonical Author. */
final readonly class MigrationAuthorCredit implements
    AuthorMaterializationCredit
{
    public function __construct(
        private WorkId $workId,
        private AuthorId $authorId,
        private ContributorRole $role,
        private ContributorPosition $position,
        private string $observedDisplayName,
        private string $observationId,
        private DateTimeImmutable $observedAt
    ) {
        AuthorContributorCreditKey::normalizeObservedName($observedDisplayName);
        IdentifierConstraints::assertValid(
            $observationId,
            "Migration Author observation ID"
        );
        $this->sourceIdentity();
    }

    public function workId(): WorkId { return $this->workId; }
    public function authorId(): AuthorId { return $this->authorId; }
    public function role(): ContributorRole { return $this->role; }
    public function position(): ContributorPosition { return $this->position; }
    public function observedDisplayName(): string
    {
        return $this->observedDisplayName;
    }
    public function sourceIdentity(): AuthorContributorCreditSourceIdentity
    {
        return AuthorContributorCreditSourceIdentity::migration(
            $this->observationId
        );
    }
    public function evidence(
        AuthorContributorCreditId $creditId
    ): AuthorCreditEvidence {
        return AuthorCreditEvidence::migration(
            $creditId,
            $this->observationId,
            $this->observedDisplayName,
            $this->role,
            $this->position,
            $this->observedAt
        );
    }
}
