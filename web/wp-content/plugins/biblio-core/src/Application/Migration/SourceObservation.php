<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;

final readonly class SourceObservation
{
    public function __construct(
        private string $id,
        private string $runId,
        private string $sourceFamily,
        private string $sourceType,
        private string $sourceId,
        private string $sourceSnapshot,
        private string $payloadHash,
        private ?string $payloadJson,
        private ?string $payloadReference,
        private string $processingStatus,
        private ?MigrationDisposition $disposition,
        private ?string $reasonCode,
        private bool $retryable,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt
    ) {
        if (preg_match('/^[0-9a-f]{64}$/', $this->id) !== 1) {
            throw new ValidationException("Migration observation ID is invalid.");
        }
        self::text($this->runId, 191, "Migration run ID");
        self::token($this->sourceFamily, "Source family");
        self::token($this->sourceType, "Source type");
        self::text($this->sourceId, 191, "Source ID");
        self::text($this->sourceSnapshot, 191, "Source snapshot");
        if (preg_match('/^[0-9a-f]{64}$/', $this->payloadHash) !== 1) {
            throw new ValidationException("Source payload hash is invalid.");
        }
        if ($this->payloadJson !== null) {
            if (strlen($this->payloadJson) > MigrationEvidence::MAX_JSON_BYTES) {
                throw new ValidationException("Source payload exceeds the storage limit.");
            }
            json_decode($this->payloadJson, true, 64, JSON_THROW_ON_ERROR);
            if (!hash_equals($this->payloadHash, hash("sha256", $this->payloadJson))) {
                throw new ValidationException("Source payload does not match its hash.");
            }
        }
        if ($this->payloadReference !== null) {
            self::text($this->payloadReference, 1024, "Payload reference");
        }
        if (!in_array($this->processingStatus, ["observed", "processing", "committed", "retryable_failure", "terminal"], true)) {
            throw new ValidationException("Invalid source processing status.");
        }
        if (in_array($this->processingStatus, ["observed", "processing"], true) !== ($this->disposition === null)) {
            throw new ValidationException("Source processing status and disposition disagree.");
        }
        if (
            in_array($this->disposition, [MigrationDisposition::IntentionallyDropped, MigrationDisposition::Failed], true)
            && ($this->reasonCode === null || trim($this->reasonCode) === "")
        ) {
            throw new ValidationException("This migration disposition requires a reason.");
        }
        if ($this->reasonCode !== null && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $this->reasonCode) !== 1) {
            throw new ValidationException("Migration observation reason is invalid.");
        }
        PersistedDateTimeConstraints::assertSupported($this->createdAt, "Observation creation time");
        PersistedDateTimeConstraints::assertSupported($this->updatedAt, "Observation update time");
        if ($this->updatedAt < $this->createdAt) {
            throw new ValidationException("Observation update precedes creation.");
        }
    }

    /** @param array<string, mixed>|null $payload */
    public static function observe(
        MigrationRun $run,
        string $sourceType,
        string $sourceId,
        string $payloadHash,
        ?array $payload,
        ?string $payloadReference,
        DateTimeImmutable $at
    ): self {
        $json = $payload === null ? null : MigrationEvidence::canonicalJson($payload);
        if ($json !== null && !hash_equals($payloadHash, hash("sha256", $json))) {
            throw new ValidationException("Canonical source payload hash mismatch.");
        }
        $id = hash("sha256", implode("\0", [
            $run->id(),
            $run->sourceFamily(),
            $sourceType,
            $sourceId,
            $run->sourceSnapshot(),
            $payloadHash,
        ]));

        return new self(
            $id,
            $run->id(),
            $run->sourceFamily(),
            $sourceType,
            $sourceId,
            $run->sourceSnapshot(),
            $payloadHash,
            $json,
            $payloadReference,
            "observed",
            null,
            null,
            false,
            $at,
            $at
        );
    }

    public function id(): string { return $this->id; }
    public function runId(): string { return $this->runId; }
    public function sourceFamily(): string { return $this->sourceFamily; }
    public function sourceType(): string { return $this->sourceType; }
    public function sourceId(): string { return $this->sourceId; }
    public function sourceSnapshot(): string { return $this->sourceSnapshot; }
    public function payloadHash(): string { return $this->payloadHash; }
    public function payloadJson(): ?string { return $this->payloadJson; }
    public function payloadReference(): ?string { return $this->payloadReference; }
    public function processingStatus(): string { return $this->processingStatus; }
    public function disposition(): ?MigrationDisposition { return $this->disposition; }
    public function reasonCode(): ?string { return $this->reasonCode; }
    public function retryable(): bool { return $this->retryable; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    private static function text(string $value, int $maxLength, string $label): void
    {
        if ($value === "" || trim($value) === "" || preg_match('//u', $value) !== 1 || mb_strlen($value) > $maxLength) {
            throw new ValidationException("{$label} is invalid.");
        }
    }

    private static function token(string $value, string $label): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $value) !== 1) {
            throw new ValidationException("{$label} is invalid.");
        }
    }
}
