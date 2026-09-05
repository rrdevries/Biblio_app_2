<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog;

use Biblio\Core\Exception\CoreFailure;
use Biblio\Core\Exception\FailureReason;
use InvalidArgumentException;

final class AmbiguousLocalEdition extends InvalidArgumentException implements CoreFailure
{
    public function __construct()
    {
        parent::__construct(
            "Multiple legacy Editions identify the same canonical ISBN-13."
        );
    }

    public function reason(): FailureReason
    {
        return FailureReason::ValidationFailed;
    }
}
