<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicTextSearchResult
{
    public function __construct(
        private BibliographicTextSearchQuery $query,
        private BibliographicAuthorSearchPage $authors,
        private BibliographicWorkSearchPage $works
    ) {
        if ($authors->query()->value() !== $query->value()
            || $works->query()->value() !== $query->value()) {
            throw new ValidationException("Bibliographic result groups do not match the query.");
        }
    }

    public function query(): BibliographicTextSearchQuery { return $this->query; }
    public function authors(): BibliographicAuthorSearchPage { return $this->authors; }
    public function works(): BibliographicWorkSearchPage { return $this->works; }
}
