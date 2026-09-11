<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Catalog\Author;
use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicAuthorSearchResult
{
    public function __construct(
        private BibliographicAuthorReference $reference,
        private string $displayName,
        private int $presentationOrder
    ) {
        self::assertText($displayName, Author::MAX_NAME_LENGTH, "Author name");
        self::assertOrder($presentationOrder);
    }

    public function reference(): BibliographicAuthorReference { return $this->reference; }
    public function displayName(): string { return $this->displayName; }
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
            BibliographicSearchGroup::Authors,
            $this->reference->kind(),
            $this->presentationOrder,
            $this->reference->resultId()
        );
    }

    public static function assertText(string $value, int $maximum, string $field): void
    {
        if (!mb_check_encoding($value, "UTF-8") || trim($value) !== $value
            || $value === "" || mb_strlen($value, "UTF-8") > $maximum) {
            throw new ValidationException("Invalid bibliographic search {$field}.");
        }
    }

    public static function assertOrder(int $order): void
    {
        if ($order < 0 || $order > 1000000) {
            throw new ValidationException("Invalid bibliographic presentation order.");
        }
    }
}
