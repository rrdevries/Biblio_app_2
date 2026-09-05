<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Query;

use Biblio\Core\Application\Catalog\Read\CatalogOverviewPageSize;

final readonly class CatalogQuery
{
    public function __construct(
        private CatalogFilters $filters = new CatalogFilters(),
        private CatalogQuerySort $sort = CatalogQuerySort::Title,
        private CatalogOverviewPageSize $pageSize = new CatalogOverviewPageSize(),
        private ?CatalogSearchTerm $search = null,
        private CatalogArchiveScope $archiveScope = CatalogArchiveScope::ActiveOnly,
        private ?CatalogQueryCursor $cursor = null
    ) {
    }

    public function filters(): CatalogFilters { return $this->filters; }
    public function sort(): CatalogQuerySort { return $this->sort; }
    public function pageSize(): CatalogOverviewPageSize { return $this->pageSize; }
    public function search(): ?CatalogSearchTerm { return $this->search; }
    public function archiveScope(): CatalogArchiveScope { return $this->archiveScope; }
    public function cursor(): ?CatalogQueryCursor { return $this->cursor; }
}
