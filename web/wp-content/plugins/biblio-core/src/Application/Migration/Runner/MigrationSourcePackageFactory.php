<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

interface MigrationSourcePackageFactory
{
    public function build(string $sourceRoot): MigrationSourcePackage;
}
