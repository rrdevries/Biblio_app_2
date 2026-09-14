<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

interface MigrationParticipantFailure
{
    public function reasonCode(): string;
}
