<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicTextSearchResult
{
    /**
     * @param list<BibliographicSearchProviderAttempt> $authorProviderAttempts
     * @param list<BibliographicSearchProviderAttempt> $workProviderAttempts
     */
    public function __construct(
        private BibliographicTextSearchQuery $query,
        private BibliographicAuthorSearchPage $authors,
        private BibliographicWorkSearchPage $works,
        private array $authorProviderAttempts = [],
        private array $workProviderAttempts = []
    ) {
        if ($authors->query()->value() !== $query->value()
            || $works->query()->value() !== $query->value()) {
            throw new ValidationException("Bibliographic result groups do not match the query.");
        }
        $this->assertAttempts($authorProviderAttempts);
        $this->assertAttempts($workProviderAttempts);
    }

    public function query(): BibliographicTextSearchQuery { return $this->query; }
    public function authors(): BibliographicAuthorSearchPage { return $this->authors; }
    public function works(): BibliographicWorkSearchPage { return $this->works; }
    /** @return list<BibliographicSearchProviderAttempt> */
    public function authorProviderAttempts(): array { return $this->authorProviderAttempts; }
    /** @return list<BibliographicSearchProviderAttempt> */
    public function workProviderAttempts(): array { return $this->workProviderAttempts; }

    /** @param array<mixed> $attempts */
    private function assertAttempts(array $attempts): void
    {
        if (!array_is_list($attempts)) {
            throw new ValidationException("Bibliographic provider attempts must be a list.");
        }
        foreach ($attempts as $attempt) {
            if (!$attempt instanceof BibliographicSearchProviderAttempt) {
                throw new ValidationException("Bibliographic provider attempts contain invalid data.");
            }
        }
    }
}
