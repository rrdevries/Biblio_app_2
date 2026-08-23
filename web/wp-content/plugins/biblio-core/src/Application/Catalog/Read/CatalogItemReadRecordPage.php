<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

final readonly class CatalogItemReadRecordPage
{
    /** @param list<CatalogItemReadRecord> $records */
    public function __construct(
        private array $records,
        private ?CatalogOverviewCursor $nextCursor
    ) {
    }

    /** @return list<CatalogItemReadRecord> */
    public function records(): array { return $this->records; }
    public function nextCursor(): ?CatalogOverviewCursor { return $this->nextCursor; }
}
