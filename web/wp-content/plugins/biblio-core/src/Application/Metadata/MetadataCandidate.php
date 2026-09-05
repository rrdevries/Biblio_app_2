<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MetadataCandidate
{
    /**
     * @param list<string> $contributors
     * @param list<string> $languages
     * @param list<string> $publishers
     */
    public function __construct(
        private string $providerKey,
        private string $providerRecordId,
        private DateTimeImmutable $retrievedAt,
        private MetadataMatchMethod $matchMethod,
        private CanonicalIsbnIdentity $queriedIsbn,
        private CanonicalIsbnIdentity $returnedIsbn,
        private ?string $title,
        private ?string $subtitle,
        private array $contributors,
        private array $languages,
        private array $publishers,
        private ?string $publicationDate,
        private ?int $pageCount,
        private ?string $format,
        private ?MetadataWorkLink $workLink
    ) {
        self::assertText($providerKey, 32, "provider key");
        self::assertText($providerRecordId, 64, "provider record ID");
        self::assertOptionalText($title, 512, "title");
        self::assertOptionalText($subtitle, 512, "subtitle");
        self::assertTextList($contributors, 32, 255, "contributors");
        self::assertTextList($languages, 16, 16, "languages");
        self::assertTextList($publishers, 16, 255, "publishers");
        self::assertOptionalText($publicationDate, 64, "publication date");
        self::assertOptionalText($format, 128, "format");

        if ($pageCount !== null && ($pageCount < 1 || $pageCount > 100000)) {
            throw new InvalidArgumentException("Invalid metadata page count.");
        }
    }

    public function providerKey(): string { return $this->providerKey; }
    public function providerRecordId(): string { return $this->providerRecordId; }
    public function retrievedAt(): DateTimeImmutable { return $this->retrievedAt; }
    public function matchMethod(): MetadataMatchMethod { return $this->matchMethod; }
    public function queriedIsbn(): CanonicalIsbnIdentity { return $this->queriedIsbn; }
    public function returnedIsbn(): CanonicalIsbnIdentity { return $this->returnedIsbn; }
    public function title(): ?string { return $this->title; }
    public function subtitle(): ?string { return $this->subtitle; }

    /** @return list<string> */
    public function contributors(): array { return $this->contributors; }

    /** @return list<string> */
    public function languages(): array { return $this->languages; }

    /** @return list<string> */
    public function publishers(): array { return $this->publishers; }

    public function publicationDate(): ?string { return $this->publicationDate; }
    public function pageCount(): ?int { return $this->pageCount; }
    public function format(): ?string { return $this->format; }
    public function workLink(): ?MetadataWorkLink { return $this->workLink; }

    private static function assertText(string $value, int $maximum, string $field): void
    {
        if (
            trim($value) !== $value
            || $value === ""
            || !mb_check_encoding($value, "UTF-8")
            || mb_strlen($value) > $maximum
        ) {
            throw new InvalidArgumentException("Invalid metadata {$field}.");
        }
    }

    private static function assertOptionalText(?string $value, int $maximum, string $field): void
    {
        if ($value !== null) {
            self::assertText($value, $maximum, $field);
        }
    }

    /** @param list<string> $values */
    private static function assertTextList(
        array $values,
        int $maximumValues,
        int $maximumLength,
        string $field
    ): void {
        if (count($values) > $maximumValues) {
            throw new InvalidArgumentException("Too many metadata {$field}.");
        }

        foreach ($values as $value) {
            self::assertText($value, $maximumLength, $field);
        }
    }
}
