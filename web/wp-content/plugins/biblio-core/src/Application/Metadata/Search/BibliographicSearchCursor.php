<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicSearchCursor
{
    public function __construct(
        private BibliographicTextSearchQuery $query,
        private BibliographicSearchGroup $group,
        private BibliographicSearchResultKind $kind,
        private int $presentationOrder,
        private string $resultId
    ) {
        if ($presentationOrder < 0 || $presentationOrder > 1000000) {
            throw new ValidationException("Invalid bibliographic search cursor order.");
        }
        $entity = $group === BibliographicSearchGroup::Authors ? "author" : "work";
        if (preg_match('/^search-' . $entity . '-[a-f0-9]{64}$/D', $resultId) !== 1) {
            throw new ValidationException("Invalid bibliographic search cursor identity.");
        }
    }

    public function query(): BibliographicTextSearchQuery { return $this->query; }
    public function group(): BibliographicSearchGroup { return $this->group; }
    public function kind(): BibliographicSearchResultKind { return $this->kind; }
    public function presentationOrder(): int { return $this->presentationOrder; }
    public function resultId(): string { return $this->resultId; }

    /** @return array{int,int,string} */
    public function sortKey(): array
    {
        return [$this->kind->sortTier(), $this->presentationOrder, $this->resultId];
    }
}
