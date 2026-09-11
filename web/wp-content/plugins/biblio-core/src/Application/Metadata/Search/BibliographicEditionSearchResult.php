<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Exception\ValidationException;
use DateTimeImmutable;

final readonly class BibliographicEditionSearchResult
{
    /**
     * @param list<string> $contributors
     * @param list<string> $languages
     * @param list<string> $publishers
     */
    public function __construct(
        private BibliographicEditionReference $reference,
        private BibliographicWorkReference $parentWork,
        private string $title,
        private ?CanonicalIsbnIdentity $isbn,
        private ?string $subtitle,
        private array $contributors,
        private array $languages,
        private array $publishers,
        private ?string $publicationDate,
        private ?int $pageCount,
        private ?string $format,
        private int $presentationOrder,
        private ?DateTimeImmutable $retrievedAt = null,
        private ?MetadataMatchMethod $matchMethod = null,
        private ?BibliographicProviderEntityIdentity $providerWorkIdentity = null
    ) {
        BibliographicAuthorSearchResult::assertText($title, Edition::MAX_TITLE_LENGTH, "Edition title");
        self::optionalText($subtitle, Edition::MAX_TITLE_LENGTH, "Edition subtitle");
        self::textList($contributors, 32, 255, "Edition contributors");
        self::textList($languages, 16, 16, "Edition languages");
        self::textList($publishers, 16, 255, "Edition publishers");
        self::optionalText($publicationDate, 64, "Edition publication date");
        self::optionalText($format, 128, "Edition format");
        if ($pageCount !== null && ($pageCount < 1 || $pageCount > 100000)) {
            throw new ValidationException("Edition page count is invalid.");
        }
        BibliographicAuthorSearchResult::assertOrder($presentationOrder);
        $external = $reference->kind() === BibliographicSearchResultKind::ExternalCandidate;
        if ($external !== ($retrievedAt !== null
            && $matchMethod !== null
            && $providerWorkIdentity !== null)) {
            throw new ValidationException("Edition provider evidence is incomplete.");
        }
        $editionProvider = $reference->providerIdentity();
        if ($providerWorkIdentity !== null
            && ($providerWorkIdentity->entityType() !== BibliographicProviderEntityType::Work
                || $editionProvider === null
                || $providerWorkIdentity->providerKey() !== $editionProvider->providerKey())) {
            throw new ValidationException("Edition provider Work evidence is invalid.");
        }
    }

    public function reference(): BibliographicEditionReference { return $this->reference; }
    public function parentWork(): BibliographicWorkReference { return $this->parentWork; }
    public function title(): string { return $this->title; }
    public function isbn(): ?CanonicalIsbnIdentity { return $this->isbn; }
    public function subtitle(): ?string { return $this->subtitle; }
    /** @return list<string> */ public function contributors(): array { return $this->contributors; }
    /** @return list<string> */ public function languages(): array { return $this->languages; }
    /** @return list<string> */ public function publishers(): array { return $this->publishers; }
    public function publicationDate(): ?string { return $this->publicationDate; }
    public function pageCount(): ?int { return $this->pageCount; }
    public function format(): ?string { return $this->format; }
    public function presentationOrder(): int { return $this->presentationOrder; }
    public function retrievedAt(): ?DateTimeImmutable { return $this->retrievedAt; }
    public function matchMethod(): ?MetadataMatchMethod { return $this->matchMethod; }
    public function providerWorkIdentity(): ?BibliographicProviderEntityIdentity
    {
        return $this->providerWorkIdentity;
    }
    public function requiresMaterialization(): bool
    {
        return $this->reference->kind() === BibliographicSearchResultKind::ExternalCandidate;
    }
    public function canAddWorkOnly(): bool { return true; }
    public function canAddEditionSpecific(): bool { return true; }

    /** @return list<string> */
    public function strongIdentityKeys(): array
    {
        $keys = $this->reference->strongIdentityKeys();
        if ($this->isbn !== null) {
            $keys[] = "edition\0isbn\0" . $this->isbn->isbn13()->value();
        }
        return $keys;
    }

    /** @return array{int,int,string} */
    public function sortKey(): array
    {
        return [
            $this->reference->kind()->sortTier(),
            $this->presentationOrder,
            $this->reference->resultId(),
        ];
    }

    private static function optionalText(?string $value, int $maximum, string $field): void
    {
        if ($value !== null) {
            BibliographicAuthorSearchResult::assertText($value, $maximum, $field);
        }
    }

    /** @param array<mixed> $values */
    private static function textList(array $values, int $maximumValues, int $maximumLength, string $field): void
    {
        if (!array_is_list($values) || count($values) > $maximumValues) {
            throw new ValidationException("{$field} must be a bounded list.");
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new ValidationException("{$field} contain invalid data.");
            }
            BibliographicAuthorSearchResult::assertText($value, $maximumLength, $field);
        }
    }
}
