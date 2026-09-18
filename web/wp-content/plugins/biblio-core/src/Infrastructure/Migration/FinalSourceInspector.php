<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Runner\MigrationRunnerFailure;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerReason;
use Biblio\Core\Application\Migration\Runner\MigrationSourceAdapter;
use Biblio\Core\Application\Migration\Runner\MigrationSourceInspection;
use Biblio\Core\Application\Migration\Runner\MigrationSourcePackageFactory;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;

final readonly class FinalSourceInspector
{
    public function __construct(private MigrationSourcePackageFactory $packages)
    {
    }

    public function inspect(
        string $sourceRoot,
        MigrationSourceAdapter $adapter
    ): MigrationSourceInspection {
        $package = $this->packages->build($sourceRoot);
        $profile = $adapter->profile($package);
        if (!$adapter->supportsVersion($profile->sourceVersion())) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::UnsupportedVersion,
                "Final source version is not explicitly supported."
            );
        }
        $records = [];
        $identities = [];
        foreach ($adapter->records($package, $profile) as $record) {
            $key = $record->sourceType() . "\0" . $record->sourceId();
            if (isset($identities[$key])) {
                throw new MigrationRunnerFailure(
                    MigrationRunnerReason::SourceDuplicate,
                    "Final source contains a duplicate logical source identity."
                );
            }
            $identities[$key] = true;
            $records[] = $record;
        }
        usort($records, static fn (MigrationSourceRecord $a, MigrationSourceRecord $b): int =>
            [$a->sourceType(), $a->sourceId(), $a->payloadHash()]
                <=> [$b->sourceType(), $b->sourceId(), $b->payloadHash()]);
        $typeCounts = [];
        foreach ($records as $record) {
            $typeCounts[$record->sourceType()] = ($typeCounts[$record->sourceType()] ?? 0) + 1;
        }
        ksort($typeCounts, SORT_STRING);

        return new MigrationSourceInspection(
            $package,
            $adapter,
            $profile,
            $records,
            $typeCounts
        );
    }
}
