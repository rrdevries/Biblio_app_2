<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Catalog\Author;
use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicAuthorSearchResult
{
    private BibliographicAuthorMatchQuality $matchQuality;
    private string $normalizedName;
    private string $nameGroupId;

    public function __construct(
        private BibliographicAuthorReference $reference,
        private string $displayName,
        private int $presentationOrder,
        private BibliographicTextSearchQuery $query
    ) {
        self::assertText($displayName, Author::MAX_NAME_LENGTH, "Author name");
        self::assertOrder($presentationOrder);
        $this->normalizedName = BibliographicAuthorNameNormalizer::normalize($displayName);
        $this->matchQuality = $this->normalizedName
            === BibliographicAuthorNameNormalizer::normalize($query->value())
                ? BibliographicAuthorMatchQuality::Exact
                : BibliographicAuthorMatchQuality::Broader;
        $this->nameGroupId = "author-name-" . hash("sha256", $this->normalizedName);
    }

    public function reference(): BibliographicAuthorReference { return $this->reference; }
    public function displayName(): string { return $this->displayName; }
    public function presentationOrder(): int { return $this->presentationOrder; }
    public function matchQuality(): BibliographicAuthorMatchQuality { return $this->matchQuality; }
    public function nameGroupId(): string { return $this->nameGroupId; }

    public function withReference(BibliographicAuthorReference $reference): self
    {
        $result = new self(
            $reference,
            $this->displayName,
            $this->presentationOrder,
            $this->query
        );
        return $result;
    }

    /** @return array{int,int,int,string} */
    public function sortKey(): array
    {
        return [
            $this->reference->kind()->sortTier(),
            $this->matchQuality->sortTier(),
            $this->presentationOrder,
            $this->reference->resultId(),
        ];
    }

    /** @return array{int,string} */
    public function sourceOrderKey(): array
    {
        return [$this->presentationOrder, $this->reference->resultId()];
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
