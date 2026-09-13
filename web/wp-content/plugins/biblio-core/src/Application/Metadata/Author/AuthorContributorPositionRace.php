<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use RuntimeException;

final class AuthorContributorPositionRace extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            "Author contributor position race requires a complete transaction retry."
        );
    }
}
