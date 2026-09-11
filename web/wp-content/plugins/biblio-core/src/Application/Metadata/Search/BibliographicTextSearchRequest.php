<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicTextSearchRequest
{
    public function __construct(
        private BibliographicTextSearchQuery $query,
        private ?BibliographicSearchCursor $authorCursor = null,
        private ?BibliographicSearchCursor $workCursor = null
    ) {
        $this->assertCursor($authorCursor, BibliographicSearchGroup::Authors);
        $this->assertCursor($workCursor, BibliographicSearchGroup::Works);
    }

    public function query(): BibliographicTextSearchQuery { return $this->query; }
    public function authorCursor(): ?BibliographicSearchCursor { return $this->authorCursor; }
    public function workCursor(): ?BibliographicSearchCursor { return $this->workCursor; }

    private function assertCursor(
        ?BibliographicSearchCursor $cursor,
        BibliographicSearchGroup $group
    ): void {
        if ($cursor === null) { return; }
        if ($cursor->group() !== $group) {
            throw new ValidationException("Bibliographic cursor has the wrong result group.");
        }
        if ($cursor->query()->value() !== $this->query->value()) {
            throw new ValidationException("Bibliographic cursor does not match the query.");
        }
    }
}
