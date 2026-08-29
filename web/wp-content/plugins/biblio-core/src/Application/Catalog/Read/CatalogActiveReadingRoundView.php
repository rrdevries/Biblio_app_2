<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundVersion;

final readonly class CatalogActiveReadingRoundView
{
    public function __construct(
        private ReadingRoundId $readingRoundId,
        private ReadingRoundVersion $version,
        private ?ReadingDate $startedOn
    ) {
    }

    public function readingRoundId(): ReadingRoundId { return $this->readingRoundId; }
    public function version(): ReadingRoundVersion { return $this->version; }
    public function startedOn(): ?ReadingDate { return $this->startedOn; }
}
