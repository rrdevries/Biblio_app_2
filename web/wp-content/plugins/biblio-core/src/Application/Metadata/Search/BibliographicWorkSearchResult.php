<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Catalog\Work;
use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicWorkSearchResult
{
    /**
     * @param list<BibliographicWorkAuthor> $authors
     * @param list<BibliographicWorkSeriesContext> $series
     */
    public function __construct(
        private BibliographicWorkReference $reference,
        private string $title,
        private array $authors,
        private array $series,
        private int $presentationOrder
    ) {
        BibliographicAuthorSearchResult::assertText($title, Work::MAX_TITLE_LENGTH, "Work title");
        self::assertTypedList($authors, BibliographicWorkAuthor::class, "Authors");
        self::assertTypedList($series, BibliographicWorkSeriesContext::class, "Series");
        BibliographicAuthorSearchResult::assertOrder($presentationOrder);
    }

    public function reference(): BibliographicWorkReference { return $this->reference; }
    public function title(): string { return $this->title; }
    /** @return list<BibliographicWorkAuthor> */ public function authors(): array { return $this->authors; }
    /** @return list<BibliographicWorkSeriesContext> */ public function series(): array { return $this->series; }
    public function presentationOrder(): int { return $this->presentationOrder; }

    /** @return array{int,int,string} */
    public function sortKey(): array
    {
        return [
            $this->reference->kind()->sortTier(),
            $this->presentationOrder,
            $this->reference->resultId(),
        ];
    }

    public function cursor(BibliographicTextSearchQuery $query): BibliographicSearchCursor
    {
        return new BibliographicSearchCursor(
            $query,
            BibliographicSearchGroup::Works,
            $this->reference->kind(),
            $this->presentationOrder,
            $this->reference->resultId()
        );
    }

    /** @param array<mixed> $values */
    private static function assertTypedList(array $values, string $class, string $field): void
    {
        if (!array_is_list($values)) {
            throw new ValidationException("Bibliographic Work {$field} must be a list.");
        }
        foreach ($values as $value) {
            if (!$value instanceof $class) {
                throw new ValidationException("Bibliographic Work {$field} contain invalid data.");
            }
        }
    }
}
