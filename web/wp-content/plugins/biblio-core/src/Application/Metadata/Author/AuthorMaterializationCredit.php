<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Catalog\{ContributorPosition,ContributorRole,WorkId};

interface AuthorMaterializationCredit
{
    public function workId(): WorkId;
    public function role(): ContributorRole;
    public function position(): ContributorPosition;
    public function observedDisplayName(): string;
    public function sourceIdentity(): AuthorContributorCreditSourceIdentity;
    public function evidence(
        AuthorContributorCreditId $creditId
    ): AuthorCreditEvidence;
}
