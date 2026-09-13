<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use RuntimeException;

final class AuthorContributorCreditConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            "Author contributor credit identity conflicts with stored evidence."
        );
    }
}
