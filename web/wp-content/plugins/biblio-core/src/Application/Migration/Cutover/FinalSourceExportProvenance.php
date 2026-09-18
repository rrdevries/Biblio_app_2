<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Exception\ValidationException;
use DateTimeImmutable;

final readonly class FinalSourceExportProvenance
{
    public function __construct(
        private string $exportedAt,
        private string $operatorId,
        private string $exportTool,
        private string $exportToolVersion,
        private string $exportInvocationDigest,
        private string $sourceBuild,
        private string $sourceRuntimeFingerprint,
        private string $freezeEvidenceId
    ) {
        if (preg_match(
            '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|\+00:00)$/D',
            $this->exportedAt
        ) !== 1) {
            throw new ValidationException("Final source export timestamp is invalid.");
        }
        try {
            $instant = new DateTimeImmutable($this->exportedAt);
        } catch (\Throwable $exception) {
            throw new ValidationException("Final source export timestamp is invalid.", 0, $exception);
        }
        if ($instant->format("P") !== "+00:00") {
            throw new ValidationException("Final source export timestamp must be UTC.");
        }
        foreach ([
            $this->operatorId, $this->exportTool, $this->exportToolVersion,
            $this->sourceBuild, $this->sourceRuntimeFingerprint, $this->freezeEvidenceId,
        ] as $value) {
            if (trim($value) === "" || mb_strlen($value) > 191) {
                throw new ValidationException("Final source export provenance is invalid.");
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $this->exportInvocationDigest) !== 1) {
            throw new ValidationException("Final source export invocation digest is invalid.");
        }
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return [
            "exported_at" => $this->exportedAt,
            "operator_id" => $this->operatorId,
            "export_tool" => $this->exportTool,
            "export_tool_version" => $this->exportToolVersion,
            "export_invocation_digest" => $this->exportInvocationDigest,
            "source_build" => $this->sourceBuild,
            "source_runtime_fingerprint" => $this->sourceRuntimeFingerprint,
            "freeze_evidence_id" => $this->freezeEvidenceId,
        ];
    }
}
