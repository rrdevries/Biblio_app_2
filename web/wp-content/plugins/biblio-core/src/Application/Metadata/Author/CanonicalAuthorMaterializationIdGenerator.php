<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Catalog\AuthorId;

interface CanonicalAuthorMaterializationIdGenerator
{
    public function nextAuthorId(): AuthorId;
    public function nextCreditId(): AuthorContributorCreditId;
}
