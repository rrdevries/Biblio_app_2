<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryCandidate;
use Biblio\Core\Application\Metadata\Discovery\BibliographicQueryType;
use Biblio\Core\Catalog\IsbnType;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class MetadataFieldEvidence
{
    private DateTimeImmutable $firstRetrievedAt;
    private DateTimeImmutable $lastRetrievedAt;
    private readonly MetadataEvidenceQueryType $queriedIdentifierType;

    public function __construct(
        private readonly string $providerKey,
        private readonly string $providerRecordId,
        DateTimeImmutable $firstRetrievedAt,
        DateTimeImmutable $lastRetrievedAt,
        private int $observationCount,
        private readonly MetadataMatchMethod $matchMethod,
        IsbnType|MetadataEvidenceQueryType $queriedIdentifierType,
        private readonly string $queriedIdentifier
    ) {
        $this->queriedIdentifierType = $queriedIdentifierType instanceof IsbnType
            ? MetadataEvidenceQueryType::from($queriedIdentifierType->value)
            : $queriedIdentifierType;
        if (
            preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $providerKey) !== 1
            || $providerRecordId === ""
            || trim($providerRecordId) !== $providerRecordId
            || mb_strlen($providerRecordId) > 191
            || $observationCount < 1
        ) {
            throw new InvalidArgumentException("Invalid metadata field evidence.");
        }
        if (
            ($this->queriedIdentifierType === MetadataEvidenceQueryType::Isbn10
                && preg_match('/^[0-9]{9}[0-9X]$/D', $queriedIdentifier) !== 1)
            || ($this->queriedIdentifierType === MetadataEvidenceQueryType::Isbn13
                && preg_match('/^97[89][0-9]{10}$/D', $queriedIdentifier) !== 1)
            || ($this->queriedIdentifierType === MetadataEvidenceQueryType::Text
                && ($queriedIdentifier === ""
                    || trim($queriedIdentifier) !== $queriedIdentifier
                    || !mb_check_encoding($queriedIdentifier, "UTF-8")
                    || mb_strlen($queriedIdentifier, "UTF-8") > 100))
        ) {
            throw new InvalidArgumentException("Invalid evidence query identifier.");
        }

        $utc = new DateTimeZone("UTC");
        $this->firstRetrievedAt = $firstRetrievedAt->setTimezone($utc);
        $this->lastRetrievedAt = $lastRetrievedAt->setTimezone($utc);
        if ($this->lastRetrievedAt < $this->firstRetrievedAt) {
            throw new InvalidArgumentException("Evidence retrieval interval is invalid.");
        }
    }

    public static function fromCandidate(MetadataCandidate $candidate): self
    {
        return new self(
            $candidate->providerKey(),
            $candidate->providerRecordId(),
            $candidate->retrievedAt(),
            $candidate->retrievedAt(),
            1,
            $candidate->matchMethod(),
            IsbnType::Isbn13,
            $candidate->queriedIsbn()->isbn13()->value()
        );
    }

    public static function fromDiscoveryCandidate(
        BibliographicDiscoveryCandidate $candidate
    ): self {
        $retrievedAt = $candidate->retrievedAt()
            ?? throw new InvalidArgumentException("Local candidates have no provider evidence.");
        $matchMethod = $candidate->matchMethod()
            ?? throw new InvalidArgumentException("Local candidates have no match method.");
        return new self(
            $candidate->providerKey()
                ?? throw new InvalidArgumentException("Local candidates have no provider."),
            $candidate->providerRecordId()
                ?? throw new InvalidArgumentException("Local candidates have no provider record."),
            $retrievedAt,
            $retrievedAt,
            1,
            $matchMethod,
            $candidate->queryType() === BibliographicQueryType::Isbn
                ? MetadataEvidenceQueryType::Isbn13
                : MetadataEvidenceQueryType::Text,
            $candidate->normalizedQuery()
        );
    }

    public function identity(): string
    {
        return hash("sha256", implode("\0", [
            $this->providerKey,
            $this->providerRecordId,
            $this->matchMethod->value,
            $this->queriedIdentifierType->value,
            $this->queriedIdentifier,
        ]));
    }

    public function observeAt(DateTimeImmutable $retrievedAt): void
    {
        $retrievedAt = $retrievedAt->setTimezone(new DateTimeZone("UTC"));
        if ($retrievedAt < $this->firstRetrievedAt) {
            $this->firstRetrievedAt = $retrievedAt;
        }
        if ($retrievedAt <= $this->lastRetrievedAt) { return; }
        $this->lastRetrievedAt = $retrievedAt;
        ++$this->observationCount;
    }

    public function providerKey(): string { return $this->providerKey; }
    public function providerRecordId(): string { return $this->providerRecordId; }
    public function firstRetrievedAt(): DateTimeImmutable { return $this->firstRetrievedAt; }
    public function lastRetrievedAt(): DateTimeImmutable { return $this->lastRetrievedAt; }
    public function observationCount(): int { return $this->observationCount; }
    public function matchMethod(): MetadataMatchMethod { return $this->matchMethod; }
    public function queriedIdentifierType(): MetadataEvidenceQueryType { return $this->queriedIdentifierType; }
    public function queriedIdentifier(): string { return $this->queriedIdentifier; }
}
