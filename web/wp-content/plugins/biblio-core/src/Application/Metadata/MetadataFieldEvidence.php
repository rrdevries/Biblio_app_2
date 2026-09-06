<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Catalog\IsbnType;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class MetadataFieldEvidence
{
    private DateTimeImmutable $firstRetrievedAt;
    private DateTimeImmutable $lastRetrievedAt;

    public function __construct(
        private readonly string $providerKey,
        private readonly string $providerRecordId,
        DateTimeImmutable $firstRetrievedAt,
        DateTimeImmutable $lastRetrievedAt,
        private int $observationCount,
        private readonly MetadataMatchMethod $matchMethod,
        private readonly IsbnType $queriedIdentifierType,
        private readonly string $queriedIdentifier
    ) {
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
            ($queriedIdentifierType === IsbnType::Isbn10
                && preg_match('/^[0-9]{9}[0-9X]$/D', $queriedIdentifier) !== 1)
            || ($queriedIdentifierType === IsbnType::Isbn13
                && preg_match('/^97[89][0-9]{10}$/D', $queriedIdentifier) !== 1)
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
        if ($retrievedAt > $this->lastRetrievedAt) {
            $this->lastRetrievedAt = $retrievedAt;
        }
        ++$this->observationCount;
    }

    public function providerKey(): string { return $this->providerKey; }
    public function providerRecordId(): string { return $this->providerRecordId; }
    public function firstRetrievedAt(): DateTimeImmutable { return $this->firstRetrievedAt; }
    public function lastRetrievedAt(): DateTimeImmutable { return $this->lastRetrievedAt; }
    public function observationCount(): int { return $this->observationCount; }
    public function matchMethod(): MetadataMatchMethod { return $this->matchMethod; }
    public function queriedIdentifierType(): IsbnType { return $this->queriedIdentifierType; }
    public function queriedIdentifier(): string { return $this->queriedIdentifier; }
}
