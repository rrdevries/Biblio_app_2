<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading\Read;

use Biblio\Core\Catalog\WorkId;

final readonly class NextReadingWorkView
{
    public function __construct(private WorkId $workId, private string $title) {}
    public function workId(): WorkId { return $this->workId; }
    public function title(): string { return $this->title; }
}
