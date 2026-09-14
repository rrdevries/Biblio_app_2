<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Application\Identity\PersonalMigrationTarget;

final readonly class MigrationPlanningTarget
{
    public function __construct(private PersonalMigrationTarget $target)
    {
    }

    public function userId(): string { return $this->target->userId()->value(); }
    public function libraryId(): string { return $this->target->libraryId()->value(); }
}
