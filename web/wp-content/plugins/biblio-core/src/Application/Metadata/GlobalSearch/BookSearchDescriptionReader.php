<?php

declare(strict_types=1);
namespace Biblio\Core\Application\Metadata\GlobalSearch;
interface BookSearchDescriptionReader
{
    /** Exact server-selected provider identity only. Plain text, bounded, no persistence. */
    public function read(?array $source): array;
}
