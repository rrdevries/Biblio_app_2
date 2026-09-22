<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

enum RehearsalFault: string
{
    case None = "none";
    case AfterProductCommit = "after_product_commit";
    case AfterPreservationCommit = "after_preservation_commit";
}
