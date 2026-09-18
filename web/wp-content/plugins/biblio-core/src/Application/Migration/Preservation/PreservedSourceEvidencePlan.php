<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Preservation;

use Biblio\Core\Application\Migration\Runner\SourceMappingExclusionPlan;
use Biblio\Core\Exception\ValidationException;

final readonly class PreservedSourceEvidencePlan implements SourceMappingExclusionPlan
{
    /** @var list<array{source_type:string,source_id:string}> */
    private array $forbiddenSourceIdentities;

    /** @param list<array<string,mixed>> $forbiddenSourceIdentities */
    public function __construct(
        private string $sourceIdentity,
        private string $evidenceType,
        private string $reasonCode,
        private string $adapterId,
        private string $sourceFamily,
        private string $sourceVersion,
        private string $manifestSha256,
        private string $mappingContract,
        private string $sourceFile,
        private string $sourceCollection,
        private string $sourceEntityId,
        private string $sourceField,
        private string $evidenceSha256,
        private PreservedSourceEvidencePrivacy $privacy,
        private int $occurrenceCount = 1,
        array $forbiddenSourceIdentities = []
    ) {
        foreach ([$this->evidenceType, $this->reasonCode, $this->adapterId, $this->sourceFamily] as $token) {
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $token) !== 1) {
                throw new ValidationException("Preserved evidence token is invalid.");
            }
        }
        if (
            trim($this->sourceIdentity) === ""
            || mb_strlen($this->sourceIdentity) > 191
            || trim($this->sourceVersion) === ""
            || mb_strlen($this->sourceVersion) > 191
            || trim($this->mappingContract) === ""
            || mb_strlen($this->mappingContract) > 191
            || preg_match('/^[a-f0-9]{64}$/D', $this->manifestSha256) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $this->evidenceSha256) !== 1
            || preg_match('/^[a-z0-9][a-z0-9_\/-]{0,190}\.json$/D', $this->sourceFile) !== 1
            || preg_match('/^[a-z][A-Za-z0-9_]{0,63}$/D', $this->sourceCollection) !== 1
            || trim($this->sourceEntityId) === ""
            || mb_strlen($this->sourceEntityId) > 191
            || preg_match('/^[a-z][A-Za-z0-9_]{0,63}$/D', $this->sourceField) !== 1
            || $this->occurrenceCount < 1
        ) {
            throw new ValidationException("Preserved source evidence plan is invalid.");
        }
        if (mb_strlen($this->locator()) > 1024) {
            throw new ValidationException("Preserved source evidence locator is too long.");
        }
        $seen = [];
        $validated = [];
        foreach ($forbiddenSourceIdentities as $identity) {
            $sourceType = $identity["source_type"] ?? null;
            $sourceId = $identity["source_id"] ?? null;
            if (
                !is_string($sourceType)
                || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $sourceType) !== 1
                || !is_string($sourceId)
                || trim($sourceId) === ""
                || mb_strlen($sourceId) > 191
            ) {
                throw new ValidationException(
                    "Forbidden migration source identity is invalid."
                );
            }
            $key = $sourceType . "\0" . $sourceId;
            if (isset($seen[$key])) {
                throw new ValidationException(
                    "Forbidden migration source identity is invalid."
                );
            }
            $seen[$key] = true;
            $validated[] = ["source_type" => $sourceType, "source_id" => $sourceId];
        }
        $this->forbiddenSourceIdentities = $validated;
    }

    public function sourceIdentity(): string { return $this->sourceIdentity; }
    public function evidenceType(): string { return $this->evidenceType; }
    public function reasonCode(): string { return $this->reasonCode; }
    public function adapterId(): string { return $this->adapterId; }
    public function sourceFamily(): string { return $this->sourceFamily; }
    public function sourceVersion(): string { return $this->sourceVersion; }
    public function manifestSha256(): string { return $this->manifestSha256; }
    public function mappingContract(): string { return $this->mappingContract; }
    public function sourceFile(): string { return $this->sourceFile; }
    public function sourceCollection(): string { return $this->sourceCollection; }
    public function sourceEntityId(): string { return $this->sourceEntityId; }
    public function sourceField(): string { return $this->sourceField; }
    public function evidenceSha256(): string { return $this->evidenceSha256; }
    public function privacy(): PreservedSourceEvidencePrivacy { return $this->privacy; }
    public function occurrenceCount(): int { return $this->occurrenceCount; }
    /** @return list<array{source_type:string,source_id:string}> */
    public function forbiddenSourceIdentities(): array
    {
        $identities = $this->forbiddenSourceIdentities;
        usort($identities, static fn (array $a, array $b): int =>
            [$a["source_type"], $a["source_id"]]
                <=> [$b["source_type"], $b["source_id"]]);
        return $identities;
    }

    public function locator(): string
    {
        return $this->sourceFile . "#" . $this->sourceCollection . "/"
            . rawurlencode($this->sourceEntityId) . "/" . $this->sourceField;
    }

    /** @return array<string, mixed> */
    public function evidenceDescriptor(): array
    {
        return [
            "adapter_id" => $this->adapterId,
            "evidence_sha256" => $this->evidenceSha256,
            "evidence_type" => $this->evidenceType,
            "manifest_sha256" => $this->manifestSha256,
            "mapping_contract" => $this->mappingContract,
            "occurrence_count" => $this->occurrenceCount,
            "privacy_class" => $this->privacy->value,
            "source_family" => $this->sourceFamily,
            "source_identity" => $this->sourceIdentity,
            "source_version" => $this->sourceVersion,
        ];
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        $payload = array_merge($this->evidenceDescriptor(), [
            "disposition" => "preserved_deferred",
            "locator" => $this->locator(),
            "reason_code" => $this->reasonCode,
        ]);
        if ($this->forbiddenSourceIdentities !== []) {
            $payload["forbidden_source_identities"] =
                $this->forbiddenSourceIdentities();
        }
        return $payload;
    }
}
