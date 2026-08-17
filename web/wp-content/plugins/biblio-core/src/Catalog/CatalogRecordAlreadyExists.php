<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;
use Throwable;

final class CatalogRecordAlreadyExists extends ConflictException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            "A catalog record with this identifier already exists.",
            FailureReason::CatalogRecordAlreadyExists,
            $previous
        );
    }
}
