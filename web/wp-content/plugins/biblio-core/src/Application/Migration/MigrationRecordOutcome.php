<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

use Biblio\Core\Exception\ValidationException;

final readonly class MigrationRecordOutcome
{
    /** @var list<MigrationTargetMapping> */
    private array $mappings;
    private ?string $evidenceJson;

    /**
     * @param list<MigrationTargetMapping> $mappings
     * @param array<string, mixed>|null $evidence
     */
    private function __construct(
        private MigrationDisposition $disposition,
        array $mappings,
        private ?string $reasonCode,
        private bool $retryable,
        private ?QuarantineReason $quarantineReason,
        private ?string $explanation,
        private ?string $preservationReason,
        ?array $evidence,
        private ?string $evidenceReference
    ) {
        $seen = [];
        foreach ($mappings as $mapping) {
            $key = $mapping->targetType() . "\0" . $mapping->targetId();
            if (isset($seen[$key])) {
                throw new ValidationException("Migration target mappings must be duplicate-free.");
            }
            $seen[$key] = true;
        }
        $this->mappings = $mappings;
        $this->evidenceJson = $evidence === null
            ? null
            : MigrationEvidence::canonicalJson($evidence);

        if ($this->reasonCode !== null) {
            self::reason($this->reasonCode, "Migration outcome reason");
        }
        if ($this->preservationReason !== null) {
            self::reason($this->preservationReason, "Migration preservation reason");
        }

        if (
            in_array($this->disposition, [MigrationDisposition::Mapped, MigrationDisposition::Transformed], true)
            && $this->mappings === []
        ) {
            throw new ValidationException("Mapped or transformed source requires a target mapping.");
        }
        if (
            !in_array($this->disposition, [MigrationDisposition::Mapped, MigrationDisposition::Transformed], true)
            && $this->disposition !== MigrationDisposition::Quarantined
            && $this->disposition !== MigrationDisposition::PreservedDeferred
            && $this->mappings !== []
        ) {
            throw new ValidationException("This source disposition cannot carry target mappings.");
        }
        if (
            in_array($this->disposition, [MigrationDisposition::IntentionallyDropped, MigrationDisposition::Failed], true)
            && ($this->reasonCode === null || trim($this->reasonCode) === "")
        ) {
            throw new ValidationException("This migration disposition requires a reason.");
        }
        if ($this->disposition === MigrationDisposition::Quarantined) {
            if ($this->quarantineReason === null || $this->explanation === null || trim($this->explanation) === "") {
                throw new ValidationException("Quarantine requires a fixed reason and safe explanation.");
            }
        } elseif ($this->quarantineReason !== null || $this->explanation !== null) {
            throw new ValidationException("Quarantine details require quarantined disposition.");
        }
        if ($this->disposition === MigrationDisposition::PreservedDeferred) {
            if ($this->preservationReason === null || trim($this->preservationReason) === "") {
                throw new ValidationException("Preservation requires a reason.");
            }
        } elseif ($this->preservationReason !== null) {
            throw new ValidationException("Preservation reason requires preserved disposition.");
        }
        if (
            $this->explanation !== null
            && (preg_match('//u', $this->explanation) !== 1 || mb_strlen($this->explanation) > 1024)
        ) {
            throw new ValidationException("Quarantine explanation is invalid.");
        }
        if (
            $this->evidenceReference !== null
            && (
                trim($this->evidenceReference) === ""
                || preg_match('//u', $this->evidenceReference) !== 1
                || mb_strlen($this->evidenceReference) > 1024
            )
        ) {
            throw new ValidationException("Migration evidence reference is invalid.");
        }
    }

    /** @param list<MigrationTargetMapping> $mappings */
    public static function mapped(array $mappings): self
    {
        return new self(MigrationDisposition::Mapped, $mappings, null, false, null, null, null, null, null);
    }

    /** @param list<MigrationTargetMapping> $mappings */
    public static function transformed(array $mappings, string $reasonCode): self
    {
        return new self(MigrationDisposition::Transformed, $mappings, $reasonCode, false, null, null, null, null, null);
    }

    /**
     * @param list<MigrationTargetMapping> $mappings
     * @param array<string, mixed>|null $evidence
     */
    public static function preserved(
        string $reason,
        ?array $evidence = null,
        ?string $evidenceReference = null,
        array $mappings = []
    ): self {
        return new self(MigrationDisposition::PreservedDeferred, $mappings, $reason, false, null, null, $reason, $evidence, $evidenceReference);
    }

    /**
     * @param list<MigrationTargetMapping> $mappings
     * @param array<string, mixed>|null $evidence
     */
    public static function quarantined(
        QuarantineReason $reason,
        string $explanation,
        ?array $evidence = null,
        ?string $evidenceReference = null,
        array $mappings = []
    ): self {
        return new self(MigrationDisposition::Quarantined, $mappings, $reason->value, false, $reason, $explanation, null, $evidence, $evidenceReference);
    }

    public static function intentionallyDropped(string $reason): self
    {
        return new self(MigrationDisposition::IntentionallyDropped, [], $reason, false, null, null, null, null, null);
    }

    public static function failed(string $reason, bool $retryable): self
    {
        return new self(MigrationDisposition::Failed, [], $reason, $retryable, null, null, null, null, null);
    }

    public function disposition(): MigrationDisposition { return $this->disposition; }
    /** @return list<MigrationTargetMapping> */
    public function mappings(): array { return $this->mappings; }
    public function reasonCode(): ?string { return $this->reasonCode; }
    public function retryable(): bool { return $this->retryable; }
    public function quarantineReason(): ?QuarantineReason { return $this->quarantineReason; }
    public function explanation(): ?string { return $this->explanation; }
    public function preservationReason(): ?string { return $this->preservationReason; }
    public function evidenceJson(): ?string { return $this->evidenceJson; }
    public function evidenceReference(): ?string { return $this->evidenceReference; }

    public function processingStatus(): string
    {
        if ($this->disposition === MigrationDisposition::Failed) {
            return $this->retryable ? "retryable_failure" : "terminal";
        }
        if (in_array($this->disposition, [MigrationDisposition::Quarantined, MigrationDisposition::IntentionallyDropped], true)) {
            return "terminal";
        }

        return "committed";
    }

    private static function reason(string $value, string $label): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $value) !== 1) {
            throw new ValidationException("{$label} is invalid.");
        }
    }
}
