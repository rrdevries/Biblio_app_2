<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use RuntimeException;

final class AuthorContributorCreditRace extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            "Author contributor credit race requires a complete transaction retry."
        );
    }
}
