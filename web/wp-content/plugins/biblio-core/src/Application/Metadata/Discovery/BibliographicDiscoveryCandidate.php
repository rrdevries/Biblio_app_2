<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\WorkId;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BibliographicDiscoveryCandidate
{
    /**
     * @param list<string> $contributors
     * @param list<string> $languages
     * @param list<string> $publishers
     */
    private function __construct(
        private BibliographicCandidateType $type,
        private string $title,
        private ?WorkId $workId,
        private ?EditionId $editionId,
        private ?string $providerKey,
        private ?string $providerRecordId,
        private ?string $providerWorkId,
        private ?DateTimeImmutable $retrievedAt,
        private ?MetadataMatchMethod $matchMethod,
        private BibliographicQueryType $queryType,
        private string $normalizedQuery,
        private ?CanonicalIsbnIdentity $isbn,
        private ?string $subtitle,
        private array $contributors,
        private array $languages,
        private array $publishers,
        private ?string $publicationDate,
        private ?int $pageCount,
        private ?string $format,
        private int $presentationOrder
    ) {
        self::text($title, 512, "title");
        self::optionalText($subtitle, 512, "subtitle");
        self::text($normalizedQuery, 100, "query");
        self::list($contributors, 32, 255, "contributors");
        self::list($languages, 16, 16, "languages");
        self::list($publishers, 16, 255, "publishers");
        self::optionalText($publicationDate, 64, "publication date");
        self::optionalText($format, 128, "format");
        if ($pageCount !== null && ($pageCount < 1 || $pageCount > 100000)) {
            throw new InvalidArgumentException("Invalid discovery page count.");
        }
        if ($presentationOrder < 0 || $presentationOrder > 10000) {
            throw new InvalidArgumentException("Invalid discovery presentation order.");
        }

        $external = $type === BibliographicCandidateType::ExternalWork
            || $type === BibliographicCandidateType::ExternalEdition;
        if ($external) {
            if ($providerKey === null || $providerRecordId === null
                || $retrievedAt === null || $matchMethod === null
                || $workId !== null || $editionId !== null) {
                throw new InvalidArgumentException("Invalid external discovery identity.");
            }
            self::assertProviderKey($providerKey);
            self::text($providerRecordId, 191, "provider record ID");
            self::optionalText($providerWorkId, 191, "provider Work ID");
        } elseif ($providerKey !== null || $providerRecordId !== null
            || $providerWorkId !== null || $retrievedAt !== null || $matchMethod !== null) {
            throw new InvalidArgumentException("Local discovery cannot contain provider identity.");
        }

        if ($type === BibliographicCandidateType::LocalWork
            && ($workId === null || $editionId !== null)) {
            throw new InvalidArgumentException("Invalid local Work discovery identity.");
        }
        if ($type === BibliographicCandidateType::LocalEdition
            && ($workId === null || $editionId === null)) {
            throw new InvalidArgumentException("Invalid local Edition discovery identity.");
        }
        if ($type === BibliographicCandidateType::ExternalWork
            && $providerWorkId === null) {
            throw new InvalidArgumentException("External Work requires provider Work identity.");
        }
    }

    /** @param list<string> $contributors */
    public static function localWork(
        WorkId $workId,
        string $title,
        BibliographicDiscoveryQuery $query,
        int $order,
        array $contributors = []
    ): self {
        return new self(
            BibliographicCandidateType::LocalWork, $title, $workId, null,
            null, null, null, null, null, $query->type(),
            $query->normalizedValue(), null, null, $contributors, [], [],
            null, null, null, $order
        );
    }

    /** @param list<string> $contributors */
    public static function localEdition(
        WorkId $workId,
        EditionId $editionId,
        string $title,
        BibliographicDiscoveryQuery $query,
        int $order,
        ?CanonicalIsbnIdentity $isbn = null,
        array $contributors = []
    ): self {
        return new self(
            BibliographicCandidateType::LocalEdition, $title, $workId, $editionId,
            null, null, null, null, null, $query->type(),
            $query->normalizedValue(), $isbn, null, $contributors, [], [],
            null, null, null, $order
        );
    }

    /**
     * @param list<string> $contributors
     * @param list<string> $languages
     * @param list<string> $publishers
     */
    public static function external(
        BibliographicCandidateType $type,
        string $providerKey,
        string $providerRecordId,
        ?string $providerWorkId,
        DateTimeImmutable $retrievedAt,
        MetadataMatchMethod $matchMethod,
        BibliographicDiscoveryQuery $query,
        string $title,
        ?CanonicalIsbnIdentity $isbn,
        ?string $subtitle,
        array $contributors,
        array $languages,
        array $publishers,
        ?string $publicationDate,
        ?int $pageCount,
        ?string $format,
        int $presentationOrder
    ): self {
        if ($type !== BibliographicCandidateType::ExternalWork
            && $type !== BibliographicCandidateType::ExternalEdition) {
            throw new InvalidArgumentException("Discovery candidate must be external.");
        }
        return new self(
            $type, $title, null, null, $providerKey, $providerRecordId,
            $providerWorkId, $retrievedAt, $matchMethod, $query->type(),
            $query->normalizedValue(), $isbn, $subtitle, $contributors,
            $languages, $publishers, $publicationDate, $pageCount, $format,
            $presentationOrder
        );
    }

    public function id(): string
    {
        return hash("sha256", implode("\0", [
            $this->type->value,
            $this->workId?->value() ?? "",
            $this->editionId?->value() ?? "",
            $this->providerKey ?? "",
            $this->providerRecordId ?? "",
            $this->normalizedQuery,
        ]));
    }

    public function canAddWorkOnly(): bool
    {
        if ($this->type === BibliographicCandidateType::LocalWork
            || $this->type === BibliographicCandidateType::LocalEdition
            || $this->type === BibliographicCandidateType::ExternalWork) {
            return true;
        }
        return $this->providerRecordId !== null
            && ($this->providerWorkId !== null || $this->contributors !== []);
    }

    public function canAddEditionSpecific(): bool
    {
        if ($this->type === BibliographicCandidateType::LocalEdition) {
            return true;
        }
        if ($this->type !== BibliographicCandidateType::ExternalEdition) {
            return false;
        }
        return $this->isbn !== null
            || ($this->providerRecordId !== null
                && ($this->providerWorkId !== null || $this->contributors !== []));
    }

    public function type(): BibliographicCandidateType { return $this->type; }
    public function title(): string { return $this->title; }
    public function workId(): ?WorkId { return $this->workId; }
    public function editionId(): ?EditionId { return $this->editionId; }
    public function providerKey(): ?string { return $this->providerKey; }
    public function providerRecordId(): ?string { return $this->providerRecordId; }
    public function providerWorkId(): ?string { return $this->providerWorkId; }
    public function retrievedAt(): ?DateTimeImmutable { return $this->retrievedAt; }
    public function matchMethod(): ?MetadataMatchMethod { return $this->matchMethod; }
    public function queryType(): BibliographicQueryType { return $this->queryType; }
    public function normalizedQuery(): string { return $this->normalizedQuery; }
    public function isbn(): ?CanonicalIsbnIdentity { return $this->isbn; }
    public function subtitle(): ?string { return $this->subtitle; }
    /** @return list<string> */ public function contributors(): array { return $this->contributors; }
    /** @return list<string> */ public function languages(): array { return $this->languages; }
    /** @return list<string> */ public function publishers(): array { return $this->publishers; }
    public function publicationDate(): ?string { return $this->publicationDate; }
    public function pageCount(): ?int { return $this->pageCount; }
    public function format(): ?string { return $this->format; }
    public function presentationOrder(): int { return $this->presentationOrder; }

    private static function assertProviderKey(string $value): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException("Invalid discovery provider key.");
        }
    }

    private static function text(string $value, int $maximum, string $field): void
    {
        if ($value === "" || trim($value) !== $value
            || !mb_check_encoding($value, "UTF-8")
            || mb_strlen($value, "UTF-8") > $maximum) {
            throw new InvalidArgumentException("Invalid discovery {$field}.");
        }
    }

    private static function optionalText(?string $value, int $maximum, string $field): void
    {
        if ($value !== null) { self::text($value, $maximum, $field); }
    }

    /** @param array<mixed> $values */
    private static function list(array $values, int $maximumValues, int $maximumLength, string $field): void
    {
        if (!array_is_list($values) || count($values) > $maximumValues) {
            throw new InvalidArgumentException("Invalid discovery {$field}.");
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException("Invalid discovery {$field}.");
            }
            self::text($value, $maximumLength, $field);
        }
    }
}
