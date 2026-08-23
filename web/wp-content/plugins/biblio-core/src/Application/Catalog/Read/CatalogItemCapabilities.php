<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

final readonly class CatalogItemCapabilities
{
    public function __construct(
        private bool $viewItem,
        private bool $startReading
    ) {
    }

    public function canViewItem(): bool { return $this->viewItem; }
    public function canStartReading(): bool { return $this->startReading; }
}
