<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

interface MigrationSourceAdapter
{
    public function adapterId(): string;

    public function sourceFamily(): string;

    public function profile(MigrationSourcePackage $package): MigrationSourceProfile;

    public function supportsVersion(string $sourceVersion): bool;

    /** @return iterable<MigrationSourceRecord> */
    public function records(
        MigrationSourcePackage $package,
        MigrationSourceProfile $profile
    ): iterable;
}
