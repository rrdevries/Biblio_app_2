<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

final readonly class MigrationBuildProvenance
{
    public function __construct(
        private string $productVersion,
        private int $schemaVersion,
        private string $coreVersion,
        private ?string $gitRevision,
        private bool $workingTreeDirty
    ) {
    }

    /** @return array{product_version:string,schema_version:int,core_version:string,git_revision:?string,working_tree_dirty:bool} */
    public function toArray(): array
    {
        return [
            "product_version" => $this->productVersion,
            "schema_version" => $this->schemaVersion,
            "core_version" => $this->coreVersion,
            "git_revision" => $this->gitRevision,
            "working_tree_dirty" => $this->workingTreeDirty,
        ];
    }
}
