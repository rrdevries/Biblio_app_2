<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Exception\ValidationException;

final readonly class FinalSourceToolProvenance
{
    public function __construct(
        private string $gitSha,
        private bool $workingTreeDirty,
        private string $productVersion,
        private int $schemaVersion,
        private string $coreVersion,
        private string $uiVersion,
        private string $runtimeFingerprint
    ) {
        if (preg_match('/^[a-f0-9]{40}$/D', $this->gitSha) !== 1) {
            throw new ValidationException("Cutover evidence requires an exact Git SHA.");
        }
        if ($this->schemaVersion < 1) {
            throw new ValidationException("Cutover evidence schema version is invalid.");
        }
        foreach ([$this->productVersion, $this->coreVersion, $this->uiVersion, $this->runtimeFingerprint] as $value) {
            if (trim($value) === "" || mb_strlen($value) > 191) {
                throw new ValidationException("Cutover tool provenance is invalid.");
            }
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            "git_sha" => $this->gitSha,
            "working_tree_dirty" => $this->workingTreeDirty,
            "product_version" => $this->productVersion,
            "schema_version" => $this->schemaVersion,
            "core_version" => $this->coreVersion,
            "ui_version" => $this->uiVersion,
            "runtime_fingerprint" => $this->runtimeFingerprint,
        ];
    }
}
