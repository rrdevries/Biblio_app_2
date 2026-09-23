<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

/** Package accounting only: never a source record, finding or migration plan. */
final readonly class NonSourcePackageMetadata
{
    public function __construct(private MigrationSourceFile $file)
    {
    }

    /** @return array{relative_path:string,byte_size:int,sha256:string,classification:string} */
    public function toArray(): array
    {
        return $this->file->toArray() + ["classification" => "NON_SOURCE_PACKAGE_METADATA"];
    }
}
