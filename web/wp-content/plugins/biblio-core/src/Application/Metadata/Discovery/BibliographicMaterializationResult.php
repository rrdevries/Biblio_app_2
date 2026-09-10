<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\Work;

final readonly class BibliographicMaterializationResult
{
    public function __construct(
        private Work $work,
        private ?Edition $edition,
        private bool $reused
    ) {
    }

    public function work(): Work { return $this->work; }
    public function edition(): ?Edition { return $this->edition; }
    public function reused(): bool { return $this->reused; }
}
