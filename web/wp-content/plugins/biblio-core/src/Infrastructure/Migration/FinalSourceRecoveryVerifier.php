<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Cutover\FinalSourcePackageIdentity;
use Biblio\Core\Application\Migration\Preservation\PreservedSourceEvidencePlan;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerFailure;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerReason;
use Biblio\Core\Application\Migration\Runner\MigrationSourcePackageFactory;

final readonly class FinalSourceRecoveryVerifier
{
    public function __construct(
        private MigrationSourcePackageFactory $packages,
        private CurrentV1RestrictedSourceEvidenceResolver $restrictedEvidence
    ) {
    }

    public function verify(
        FinalSourcePackageIdentity $identity,
        string $archivePath,
        string $extractionRoot,
        ?PreservedSourceEvidencePlan $allowlistedLocator = null
    ): void {
        $archiveHash = is_file($archivePath) && !is_link($archivePath)
            ? @hash_file("sha256", $archivePath)
            : false;
        if (!is_string($archiveHash) || !hash_equals($identity->archiveSha256(), $archiveHash)) {
            throw $this->failure();
        }
        $package = $this->packages->build($extractionRoot);
        if (!hash_equals($identity->manifestSha256(), $package->manifestDigest())) {
            throw $this->failure();
        }
        if ($allowlistedLocator !== null) {
            $this->restrictedEvidence->verify($package, $allowlistedLocator);
        }
    }

    private function failure(): MigrationRunnerFailure
    {
        return new MigrationRunnerFailure(
            MigrationRunnerReason::SourceChanged,
            "Final source recovery verification failed without exposing restricted content."
        );
    }
}
