<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use RuntimeException;

final class AuthorProviderClaimRace extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            "Provider Author identity race requires a complete transaction retry."
        );
    }
}
