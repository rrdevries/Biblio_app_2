<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Catalog\{Series,SeriesId,SeriesPosition};

final readonly class BibliographicWorkSeriesContext
{
    public function __construct(
        private string $displayName,
        private ?SeriesId $seriesId = null,
        private ?SeriesPosition $position = null
    ) {
        BibliographicAuthorSearchResult::assertText(
            $displayName,
            Series::MAX_NAME_LENGTH,
            "Series name"
        );
    }

    public function displayName(): string { return $this->displayName; }
    public function seriesId(): ?SeriesId { return $this->seriesId; }
    public function position(): ?SeriesPosition { return $this->position; }
}
