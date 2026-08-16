<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use RuntimeException;

final class ReadingSourceUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct("Reading source is not available to this user.");
    }
}
