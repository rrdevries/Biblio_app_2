<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Migration\{BeginMigrationRunService, CommitMigrationRecordService, MigrationRunLifecycleService, ObserveSourceRecordService};
use Biblio\Core\Application\Migration\Cutover\{ApprovedRehearsalSource, GuardedRehearsal, RehearsalBackupStore, RehearsalEvidenceStore, RehearsalIsolationGuard, RehearsalTarget};
use Biblio\Core\Application\Migration\Runner\{MigrationApplyRunner, MigrationEnvironment, MigrationRunner, MigrationSourceAdapterRegistry, MigrationSourceMapperRegistry};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{CoreTableNames, WpdbLibraryBookTypeRepository, WpdbLibraryGenreRepository, WpdbMigrationLedgerRepository, WpdbTransactionManager};
use Biblio\Core\Infrastructure\WordPress\Migration\{OpaqueMigrationRunIdGenerator, SystemMigrationClock};
use wpdb;

/** Internal rehearsal composition, deliberately not a production apply command. */
final readonly class RehearsalComposition
{
    public function __construct(private wpdb $db, private CoreApplication $app, private MigrationEnvironment $environment) {}

    public function planner(ApprovedRehearsalSource $source): MigrationRunner
    {
        $tables = new CoreTableNames($this->db->prefix);
        return new MigrationRunner(
            new FilesystemMigrationSourcePackageFactory(),
            new MigrationSourceAdapterRegistry([new CurrentV1SourceAdapter()]),
            $this->app->migrationParticipants(), $this->app->personalMigrationTargets(), $this->environment,
            new MigrationSourceMapperRegistry([new CurrentV1RehearsalMapper($source,
                new WpdbLibraryBookTypeRepository($this->db, $tables), new WpdbLibraryGenreRepository($this->db, $tables))]),
            $source->bundle->digest()
        );
    }

    public function create(ApprovedRehearsalSource $source, RehearsalTarget $target, RehearsalBackupStore $backups,
        RehearsalEvidenceStore $evidence, RehearsalIsolationGuard $isolation, UserId $user, LibraryId $library): GuardedRehearsal
    {
        $tables = new CoreTableNames($this->db->prefix);
        $ledger = new WpdbMigrationLedgerRepository($this->db, $tables);
        $transactions = new WpdbTransactionManager($this->db);
        $clock = new SystemMigrationClock();
        $targets = $this->app->personalMigrationTargets();
        $planner = $this->planner($source);
        $apply = new MigrationApplyRunner($planner, $targets, $this->environment,
            new BeginMigrationRunService($targets, $ledger, $transactions, $clock, new OpaqueMigrationRunIdGenerator()),
            new ObserveSourceRecordService($ledger, $clock), new CommitMigrationRecordService($ledger, $transactions, $clock),
            new MigrationRunLifecycleService($ledger, $transactions, $clock), $ledger, $this->app->migrationReconciliation(),
            "cutover-rehearsal-01b"
        );
        return new GuardedRehearsal($source, new FilesystemMigrationSourcePackageFactory(), $planner, $apply, $targets,
            $target, $backups, $ledger, new WpdbRehearsalProductVerifier($this->db, $tables, $ledger, $this->app), $evidence, $isolation, $user, $library);
    }
}
