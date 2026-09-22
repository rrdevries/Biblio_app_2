<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Cutover\{RehearsalContract, RehearsalIsolationGuard};
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

/** This connection is NEVER passed to a writer, transport, or application composition. */
final readonly class WpdbRehearsalIsolationGuard implements RehearsalIsolationGuard
{
    public function __construct(private wpdb $protectedDatabase, private wpdb $rehearsalDatabase) {}

    public function fingerprint(): array
    {
        RehearsalContract::require($this->protectedDatabase !== $this->rehearsalDatabase
            && $this->protectedDatabase->get_var("SELECT DATABASE()") === "db"
            && $this->rehearsalDatabase->get_var("SELECT DATABASE()") !== "db", "protected_database_identity_invalid");
        return (new RehearsalDatabaseState($this->protectedDatabase, new CoreTableNames($this->protectedDatabase->prefix)))->fingerprint();
    }
}
