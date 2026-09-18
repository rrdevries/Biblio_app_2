<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\Runner\DeterministicJson;
use Biblio\Core\Exception\ValidationException;

final readonly class FinalSourcePackageIdentity
{
    public function __construct(
        private string $packageId,
        private string $archiveSha256,
        private string $manifestSha256,
        private string $sourceFamily,
        private string $sourceVersion,
        private string $adapterId
    ) {
        if (
            preg_match('/^[a-z0-9][a-z0-9._-]{2,190}$/D', $this->packageId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $this->archiveSha256) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $this->manifestSha256) !== 1
        ) {
            throw new ValidationException("Final source package identity is invalid.");
        }
        foreach ([$this->sourceFamily, $this->sourceVersion, $this->adapterId] as $value) {
            if (trim($value) === "" || mb_strlen($value) > 191) {
                throw new ValidationException("Final source provenance value is invalid.");
            }
        }
    }

    public function packageId(): string { return $this->packageId; }
    public function archiveSha256(): string { return $this->archiveSha256; }
    public function manifestSha256(): string { return $this->manifestSha256; }
    public function sourceFamily(): string { return $this->sourceFamily; }
    public function sourceVersion(): string { return $this->sourceVersion; }
    public function adapterId(): string { return $this->adapterId; }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return [
            "logical_package_id" => $this->packageId,
            "archive_sha256" => $this->archiveSha256,
            "manifest_sha256" => $this->manifestSha256,
            "source_family" => $this->sourceFamily,
            "source_version" => $this->sourceVersion,
            "adapter_id" => $this->adapterId,
        ];
    }

    public function digest(): string
    {
        return DeterministicJson::hash($this->toArray());
    }
}
