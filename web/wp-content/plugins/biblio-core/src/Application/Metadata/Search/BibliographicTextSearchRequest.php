<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicTextSearchRequest
{
    private ?BibliographicAuthorSearchCursor $authorCursor;
    private ?BibliographicSearchCursor $workCursor;

    public function __construct(
        private BibliographicTextSearchQuery $query,
        BibliographicAuthorSearchCursor|BibliographicSearchCursor|null $authorCursor = null,
        BibliographicAuthorSearchCursor|BibliographicSearchCursor|null $workCursor = null
    ) {
        if ($authorCursor !== null && !$authorCursor instanceof BibliographicAuthorSearchCursor) {
            throw new ValidationException("Bibliographic cursor has the wrong result group.");
        }
        if ($workCursor instanceof BibliographicAuthorSearchCursor
            || ($workCursor !== null && $workCursor->group() !== BibliographicSearchGroup::Works)) {
            throw new ValidationException("Bibliographic cursor has the wrong result group.");
        }
        $this->assertQuery($authorCursor);
        $this->assertQuery($workCursor);
        $this->authorCursor = $authorCursor;
        $this->workCursor = $workCursor;
    }

    public function query(): BibliographicTextSearchQuery { return $this->query; }
    public function authorCursor(): ?BibliographicAuthorSearchCursor { return $this->authorCursor; }
    public function workCursor(): ?BibliographicSearchCursor { return $this->workCursor; }

    private function assertQuery(
        BibliographicAuthorSearchCursor|BibliographicSearchCursor|null $cursor
    ): void {
        if ($cursor === null) { return; }
        if ($cursor->query()->value() !== $this->query->value()) {
            throw new ValidationException("Bibliographic cursor does not match the query.");
        }
    }
}
