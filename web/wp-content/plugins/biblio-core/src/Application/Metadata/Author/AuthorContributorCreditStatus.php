<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

enum AuthorContributorCreditStatus: string
{
    case Linked = "linked";
    case Unresolved = "unresolved";
}
