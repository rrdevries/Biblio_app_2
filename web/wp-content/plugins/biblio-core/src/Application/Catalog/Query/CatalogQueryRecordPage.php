<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Query;

final readonly class CatalogQueryRecordPage
{
    /** @param list<CatalogQueryItemRecord> $records */
    public function __construct(private array $records, private bool $hasMore)
    {
    }

    /** @return list<CatalogQueryItemRecord> */ public function records(): array { return $this->records; }
    public function hasMore(): bool { return $this->hasMore; }
}
