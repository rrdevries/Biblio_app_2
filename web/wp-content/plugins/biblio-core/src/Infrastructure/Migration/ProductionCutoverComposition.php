<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Migration\{BeginMigrationRunService, CommitMigrationRecordService, MigrationRunLifecycleService, ObserveSourceRecordService};
use Biblio\Core\Application\Migration\Cutover\{FinalSourcePlanningContext, GuardedProductionCutover, ProductionMigrationTarget, RehearsalBackupStore, RehearsalEvidenceStore};
use Biblio\Core\Application\Migration\Runner\{MigrationApplyRunner, MigrationEnvironment};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{CoreTableNames, WpdbMigrationLedgerRepository, WpdbTransactionManager};
use Biblio\Core\Infrastructure\WordPress\Migration\{OpaqueMigrationRunIdGenerator, SystemMigrationClock};
use wpdb;

final readonly class ProductionCutoverComposition
{
    public function __construct(private wpdb $db, private CoreApplication $app, private MigrationEnvironment $environment) {}

    public function create(FinalSourcePlanningContext $source, ProductionMigrationTarget $target, RehearsalBackupStore $backups,
        RehearsalEvidenceStore $evidence, UserId $user, LibraryId $library): GuardedProductionCutover
    {
        $tables = new CoreTableNames($this->db->prefix);
        $ledger = new WpdbMigrationLedgerRepository($this->db, $tables);
        $transactions = new WpdbTransactionManager($this->db);
        $clock = new SystemMigrationClock();
        $targets = $this->app->personalMigrationTargets();
        $planner = (new RehearsalComposition($this->db, $this->app, $this->environment))->preApprovalPlanner($source);
        $apply = new MigrationApplyRunner($planner, $targets, $this->environment,
            new BeginMigrationRunService($targets, $ledger, $transactions, $clock, new OpaqueMigrationRunIdGenerator()),
            new ObserveSourceRecordService($ledger, $clock), new CommitMigrationRecordService($ledger, $transactions, $clock),
            new MigrationRunLifecycleService($ledger, $transactions, $clock), $ledger, $this->app->migrationReconciliation(), "cutover-production-01");
        return new GuardedProductionCutover($source, new FilesystemMigrationSourcePackageFactory(), $planner, $apply, $targets,
            $target, $backups, $ledger, new WpdbRehearsalProductVerifier($this->db, $tables, $ledger, $this->app), $evidence, $user, $library);
    }
}
