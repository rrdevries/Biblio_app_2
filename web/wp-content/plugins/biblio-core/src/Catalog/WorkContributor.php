<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

final readonly class WorkContributor
{
    public function __construct(
        private WorkId $workId,
        private AuthorId $authorId,
        private ContributorRole $role,
        private ContributorPosition $position
    ) {
    }

    public function workId(): WorkId { return $this->workId; }
    public function authorId(): AuthorId { return $this->authorId; }
    public function role(): ContributorRole { return $this->role; }
    public function position(): ContributorPosition { return $this->position; }
}
