<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\MigrationRun;
use Biblio\Core\Application\Migration\Runner\PreparedMigrationPlan;

interface RehearsalProductVerifier
{
    /**
 * Verify expected identities/counts, integrity and owner application reads.
 * @return array<string,mixed>
 */
    public function verify(MigrationRun $run, PreparedMigrationPlan $prepared): array;
}
