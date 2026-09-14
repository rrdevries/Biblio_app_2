<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Exception\ValidationException;

final readonly class PlannedMigrationRecord
{
    /**
     * @param list<array{operation:string,target_type?:string,target_id?:string}> $operations
     * @param list<string> $unmatchedReferences
     * @param list<array{source_type:string,source_id:string}> $dependencies
     */
    public function __construct(
        private MigrationDisposition $disposition,
        private array $operations = [],
        private ?string $reasonCode = null,
        private array $unmatchedReferences = [],
        private ?string $safeExplanation = null,
        private array $dependencies = [],
        private ?TypedMigrationPlan $typedPlan = null
    ) {
        if (
            $this->reasonCode !== null
            && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $this->reasonCode) !== 1
        ) {
            throw new ValidationException("Planned migration reason is invalid.");
        }
        if (
            in_array(
                $this->disposition,
                [MigrationDisposition::Mapped, MigrationDisposition::Transformed],
                true
            )
            && $this->operations === []
        ) {
            throw new ValidationException(
                "Mapped or transformed plan requires an operation."
            );
        }
        if (
            $this->disposition !== MigrationDisposition::Mapped
            && $this->reasonCode === null
        ) {
            throw new ValidationException("Planned disposition requires a reason.");
        }
        if (
            $this->disposition === MigrationDisposition::Quarantined
            && ($this->safeExplanation === null || trim($this->safeExplanation) === "")
        ) {
            throw new ValidationException("Planned quarantine requires a safe explanation.");
        }
        if (
            $this->safeExplanation !== null
            && (preg_match('//u', $this->safeExplanation) !== 1 || mb_strlen($this->safeExplanation) > 1024)
        ) {
            throw new ValidationException("Planned safe explanation is invalid.");
        }
        if (
            in_array(
                $this->disposition,
                [MigrationDisposition::IntentionallyDropped, MigrationDisposition::Failed],
                true
            )
            && $this->operations !== []
        ) {
            throw new ValidationException("This planned disposition cannot carry operations.");
        }
        foreach ($this->operations as $operation) {
            if (trim($operation["operation"]) === "") {
                throw new ValidationException("Planned operation is invalid.");
            }
        }
        foreach ($this->dependencies as $dependency) {
            if (
                preg_match(
                    '/^[a-z0-9][a-z0-9._-]{0,63}$/',
                    $dependency["source_type"]
                ) !== 1
                || trim($dependency["source_id"]) === ""
                || mb_strlen($dependency["source_id"]) > 191
            ) {
                throw new ValidationException(
                    "Planned migration dependency is invalid."
                );
            }
        }
    }

    public function disposition(): MigrationDisposition { return $this->disposition; }
    /** @return list<array{operation:string,target_type?:string,target_id?:string}> */
    public function operations(): array { return $this->operations; }
    public function reasonCode(): ?string { return $this->reasonCode; }
    public function safeExplanation(): ?string { return $this->safeExplanation; }
    /** @return list<array{source_type:string,source_id:string}> */
    public function dependencies(): array { return $this->dependencies; }
    public function typedPlan(): ?TypedMigrationPlan { return $this->typedPlan; }
    /** @return list<string> */
    public function unmatchedReferences(): array { return $this->unmatchedReferences; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $operations = $this->operations;
        usort($operations, static fn (array $a, array $b): int =>
            DeterministicJson::encode($a) <=> DeterministicJson::encode($b));
        $references = $this->unmatchedReferences;
        sort($references, SORT_STRING);
        $dependencies = $this->dependencies;
        usort($dependencies, static fn (array $a, array $b): int =>
            [$a["source_type"], $a["source_id"]]
                <=> [$b["source_type"], $b["source_id"]]);

        return [
            "disposition" => $this->disposition->value,
            "reason_code" => $this->reasonCode,
            "safe_explanation" => $this->safeExplanation,
            "operations" => $operations,
            "unmatched_references" => $references,
            "dependencies" => $dependencies,
        ];
    }
}
