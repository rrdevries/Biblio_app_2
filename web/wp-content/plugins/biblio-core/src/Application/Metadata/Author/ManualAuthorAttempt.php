<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Catalog\{ContributorPosition, ContributorRole};
use Biblio\Core\Identity\IdentifierConstraints;

final readonly class ManualAuthorAttempt
{
    public function __construct(
        private string $observationId,
        private string $displayName,
        private ContributorRole $role,
        private ContributorPosition $position
    ) {
        IdentifierConstraints::assertValid(
            $observationId,
            "Manual Author observation ID"
        );
        AuthorContributorCreditKey::normalizeObservedName($displayName);
    }

    public function observationId(): string { return $this->observationId; }
    public function displayName(): string { return $this->displayName; }
    public function role(): ContributorRole { return $this->role; }
    public function position(): ContributorPosition { return $this->position; }
}
