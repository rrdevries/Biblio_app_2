<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

/** An adapter's explicit package-only admission policy, separate from profiling. */
interface MigrationPackageMetadataProvider
{
    /** @return list<NonSourcePackageMetadata> */
    public function packageMetadata(MigrationSourcePackage $package): array;
}
