<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\Runner\MigrationSourcePackage;

final readonly class FinalSourceIntakeReceipt
{
    public function __construct(
        private FinalSourcePackageIdentity $identity,
        private FinalSourceExportProvenance $export,
        private FinalSourceRetentionMetadata $retention,
        private MigrationSourcePackage $package,
        private string $archiveFilename,
        private int $archiveBytes,
        private string $extractionLocator
    ) {
    }

    public function identity(): FinalSourcePackageIdentity { return $this->identity; }
    public function package(): MigrationSourcePackage { return $this->package; }
    public function extractionRoot(): string { return $this->package->root(); }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            "intake_schema" => "final-source-intake-v1",
            "package" => $this->identity->toArray(),
            "archive" => [
                "filename" => $this->archiveFilename,
                "bytes" => $this->archiveBytes,
                "sha256" => $this->identity->archiveSha256(),
            ],
            "manifest" => $this->package->toArray(),
            "export_provenance" => $this->export->toArray(),
            "retention" => $this->retention->toArray(),
            "immutable_extraction_locator" => $this->extractionLocator,
            "access_class" => "restricted-migration-source",
            "zero_write" => true,
        ];
    }
}
