<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Migration\{BeginMigrationRunService, CommitMigrationRecordService, MigrationRunLifecycleService, ObserveSourceRecordService};
use Biblio\Core\Application\Migration\Cutover\{ApprovedRehearsalSource, FinalSourcePlanningContext, GuardedRehearsal, RehearsalBackupStore, RehearsalEvidenceStore, RehearsalIsolationGuard, RehearsalTarget};
use Biblio\Core\Application\Migration\Runner\{MigrationApplyRunner, MigrationEnvironment, MigrationParticipantRegistry, MigrationRunner, MigrationSourceAdapterRegistry, MigrationSourceMapperRegistry};
use Biblio\Core\Application\Migration\Series\{CatalogWorkSeriesMigrationParticipant, SeriesMigrationWriter, SeriesPreservationPromotionPolicy};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{CoreTableNames, WpdbLibraryBookTypeRepository, WpdbLibraryGenreRepository, WpdbMigrationLedgerRepository, WpdbSeriesRepository, WpdbTransactionManager, WpdbWorkRepository};
use Biblio\Core\Infrastructure\WordPress\Migration\{OpaqueMigrationRunIdGenerator, SystemMigrationClock};
use wpdb;

/** Internal rehearsal composition, deliberately not a production apply command. */
final readonly class RehearsalComposition
{
    public function __construct(private wpdb $db, private CoreApplication $app, private MigrationEnvironment $environment) {}

    public function planner(ApprovedRehearsalSource $source): MigrationRunner
    {
        return $this->buildPlanner($source->planningContext());
    }

    /** Planning-only capability: no ApprovedRehearsalSource or apply runner exists on this route. */
    public function preApprovalPlanner(FinalSourcePlanningContext $source): MigrationRunner
    {
        return $this->buildPlanner($source);
    }

    private function buildPlanner(FinalSourcePlanningContext $source): MigrationRunner
    {
        $source->verify(new FilesystemMigrationSourcePackageFactory());
        $tables = new CoreTableNames($this->db->prefix);
        // The mapper and promotion writer must share the verified candidate binding.
        // Keep the closed promotion lane and all prior-payload/replay checks intact;
        // ordinary production composition retains its historical reviewed binding.
        $contract = new CurrentV1ReviewedSeriesContract($source->intake->identity()->manifestSha256());
        $membership = new CatalogWorkSeriesMigrationParticipant(new SeriesMigrationWriter(
            new WpdbMigrationLedgerRepository($this->db, $tables),
            new WpdbSeriesRepository($this->db, $tables), new WpdbWorkRepository($this->db, $tables),
            new SeriesPreservationPromotionPolicy(
                "current_v1_contained_work_series", "contained_work_series_deferred",
                CurrentV1SourceAdapter::ADAPTER_ID, CurrentV1SourceAdapter::SOURCE_FAMILY,
                CurrentV1SourceAdapter::SOURCE_VERSION, $contract->manifestSha256(), $contract->identity(),
                "data/books.json", "books", "containedWorks"
            )
        ));
        $registry = $this->app->migrationParticipants();
        $participants = [];
        foreach (array_keys($registry->inventory()) as $type) {
            $participants[] = $type === CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE
                ? $membership : ($registry->forType($type) ?? throw new \LogicException("Registered participant missing."));
        }
        return new MigrationRunner(
            new FilesystemMigrationSourcePackageFactory(),
            new MigrationSourceAdapterRegistry([new CurrentV1SourceAdapter()]),
            new MigrationParticipantRegistry($participants), $this->app->personalMigrationTargets(), $this->environment,
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
