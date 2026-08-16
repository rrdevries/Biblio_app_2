<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use RuntimeException;

final class ActiveReadingRoundAlreadyExists extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            "An active Reading Round already exists for this source."
        );
    }
}
