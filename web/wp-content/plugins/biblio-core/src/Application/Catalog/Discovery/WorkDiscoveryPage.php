<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Discovery;

final readonly class WorkDiscoveryPage
{
    /** @param list<WorkDiscoveryView> $works */
    public function __construct(
        private array $works,
        private ?WorkDiscoveryCursor $nextCursor
    ) {
    }

    /** @return list<WorkDiscoveryView> */
    public function works(): array { return $this->works; }
    public function nextCursor(): ?WorkDiscoveryCursor { return $this->nextCursor; }
}
