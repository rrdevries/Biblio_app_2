<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;

final class CanonicalIsbnAlreadyClaimed extends ConflictException
{
    public function __construct()
    {
        parent::__construct(
            "Canonical ISBN-13 is already claimed by another Edition.",
            FailureReason::CatalogRecordAlreadyExists
        );
    }
}
