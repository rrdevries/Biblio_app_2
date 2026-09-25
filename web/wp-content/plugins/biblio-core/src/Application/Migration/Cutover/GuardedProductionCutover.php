<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Identity\MigrationTargetValidator;
use Biblio\Core\Application\Migration\{MigrationDisposition, MigrationLedgerRepository, MigrationRecordOutcome, MigrationRun, MigrationRunStatus};
use Biblio\Core\Application\Migration\Reconciliation\MigrationReconciliationReport;
use Biblio\Core\Application\Migration\Runner\{DeterministicJson, MigrationApplyObserver, MigrationApplyRunner, MigrationPlanningTarget, MigrationRunner, MigrationSourcePackageFactory, MigrationSourceRecord, PreparedMigrationPlan};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Throwable;

/** Production-only orchestration. No mapper, fault injection, automatic retry or automatic restore. */
final class GuardedProductionCutover implements MigrationApplyObserver
{
    private ?ProductionAuthorization $authorization = null;
    private ?PreparedMigrationPlan $prepared = null;
    private ?MigrationRun $run = null;
    /** @var list<string> */
    private array $phases = [];

    public function __construct(
        private readonly FinalSourcePlanningContext $source,
        private readonly MigrationSourcePackageFactory $packages,
        private readonly MigrationRunner $planner,
        private readonly MigrationApplyRunner $apply,
        private readonly MigrationTargetValidator $targets,
        private readonly ProductionMigrationTarget $target,
        private readonly RehearsalBackupStore $backups,
        private readonly MigrationLedgerRepository $ledger,
        private readonly RehearsalProductVerifier $products,
        private readonly RehearsalEvidenceStore $evidence,
        private readonly UserId $user,
        private readonly LibraryId $library
    ) {}

    /** Read-only target preparation plus a filesystem PRE backup; never creates authorization.
     * @return array<string,mixed>
     */
    public function preflight(): array
    {
        $this->target->acquire();
        try {
            $before = $this->target->fingerprint();
            $this->target->assertEmpty();
            $this->source->verify($this->packages);
            $prepared = $this->planner->prepare(
                $this->planner->inspectSource($this->source->intake->extractionRoot(), $this->source->intake->identity()->adapterId()),
                new MigrationPlanningTarget($this->targets->validate($this->user, $this->library))
            );
            $binding = $this->binding($prepared) + ["baseline" => $before];
            $backup = $this->backups->create("PRE_APPLY", $binding);
            RehearsalContract::equal($before, $this->target->fingerprint(), "production_preflight_changed_database");
            $this->source->verify($this->packages);
            $packet = ["binding" => $binding, "backup" => $backup];
            $this->evidence->append("production-preflight", $packet);
            return $packet;
        } finally {
            $this->target->release();
        }
    }

    /** @return array<string,mixed> */
    public function execute(ProductionAuthorization $authorization): array
    {
        $this->authorization = $authorization;
        $this->phases = [];
        $this->target->acquire();
        try {
            $binding = $authorization->packet["binding"];
            $this->backups->verify($authorization->packet["backup"], $binding);
            RehearsalContract::equal($binding["environment"], $this->target->identity(), "production_environment_changed");
            RehearsalContract::equal($binding["baseline"], $this->target->fingerprint(), "production_baseline_changed");
            $this->source->verify($this->packages);
            // Validate source/build/plan before even changing application availability.
            $prepared = $this->planner->prepare(
                $this->planner->inspectSource($this->source->intake->extractionRoot(), $this->source->intake->identity()->adapterId()),
                new MigrationPlanningTarget($this->targets->validate($this->user, $this->library))
            );
            RehearsalContract::equal($this->binding($prepared) + ["baseline" => $binding["baseline"]], $binding, "production_intent_changed");
            $this->target->blockWrites();
            $this->phases[] = "writes_blocked";
            $this->evidence->append("production-start", ["authorization_digest" => ProductionAuthorization::digest($authorization->packet)]);
            $result = $this->apply->apply($this->source->intake->extractionRoot(), $this->source->intake->identity()->adapterId(),
                $this->user, $this->library, $binding["plan"]["plan_set_digest"], observer: $this);
            RehearsalContract::require($result->run()->status() === MigrationRunStatus::Completed && $result->reconciliation()->accepted(), "production_reconciliation_rejected");
            $this->phases[] = "post_apply_quarantine_verification";
            $this->assertQuarantine($result->run());
            RehearsalContract::require($this->prepared !== null, "production_plan_missing");
            $this->phases[] = "post_apply_product_verification";
            $verification = $this->products->verify($result->run(), $this->prepared);
            $after = $this->target->fingerprint();
            foreach ($binding["baseline"]["tables"] as $table => $baseline) {
                if (!isset($after["biblio_tables"][$table]) || preg_match('/_biblio_(libraries|library_memberships|personal_library_designations|library_book_types|library_genres|library_subjects)$/D', $table) === 1) {
                    RehearsalContract::equal($after["tables"][$table] ?? null, $baseline, "nonmigration_state_changed");
                }
            }
            $this->source->verify($this->packages);
            $this->target->assertWritesBlocked();
            $receipt = ["authorization_digest" => ProductionAuthorization::digest($authorization->packet), "run_id" => $result->run()->id(),
                "reconciliation" => $result->reconciliation()->toArray(), "verification" => $verification, "fingerprint" => $after,
                "phases" => $this->phases, "writes_remain_blocked" => true];
            $receipt["artifact"] = $this->evidence->append("production-completed", $receipt);
            return $receipt;
        } catch (Throwable $failure) {
            $this->recordFailure($failure);
            throw $failure;
        } finally {
            $this->authorization = null;
            $this->target->release();
        }
    }

    public function restore(ProductionAuthorization $authorization, string $confirmation): void
    {
        (new ProductionRollback($this->target, $this->backups, $this->evidence))->restore($authorization, $confirmation);
    }

    public function beforeBegin(PreparedMigrationPlan $prepared): void
    {
        RehearsalContract::require($this->authorization !== null, "production_authorization_required");
        $binding = $this->authorization->packet["binding"];
        $this->target->assertWritesBlocked();
        $this->target->assertEmpty();
        $this->source->verify($this->packages);
        RehearsalContract::equal($this->binding($prepared) + ["baseline" => $binding["baseline"]], $binding, "production_intent_changed");
        RehearsalContract::equal($this->target->fingerprint(), $binding["baseline"], "production_baseline_changed");
        $this->backups->verify($this->authorization->packet["backup"], $binding);
        $this->prepared = $prepared;
        $this->phases[] = "preflight_complete";
    }

    public function begun(MigrationRun $run): void { $this->run = $run; $this->target->assertWritesBlocked(); }
    public function committed(MigrationRun $run, MigrationSourceRecord $record, MigrationRecordOutcome $outcome): bool
    {
        $this->target->assertWritesBlocked();
        return false;
    }
    public function beforeCompletion(MigrationRun $run, PreparedMigrationPlan $prepared, MigrationReconciliationReport $report): void
    {
        $this->target->assertWritesBlocked();
        $this->phases[] = "reconciliation";
        $this->evidence->append("production-reconciliation", ["run_id" => $run->id(), "report" => $report->toArray()]);
        RehearsalContract::require($report->accepted(), "production_reconciliation_rejected");
        $this->assertQuarantine($run);
    }

    /** @return array<string,mixed> */
    private function binding(PreparedMigrationPlan $prepared): array
    {
        RehearsalContract::equal($prepared->finalPopulationBundleDigest(), $this->source->bundle->digest(), "prepared_bundle_mismatch");
        $plan = RehearsalPlanEvidence::describe($prepared, []);
        RehearsalContract::equal($plan["quarantine"], $this->source->review["quarantine"], "quarantine_not_reviewed");
        return ["environment" => $this->target->identity(), "source" => ["package" => $this->source->intake->identity()->toArray(),
            "bundle_digest" => $this->source->bundle->digest(), "review_digest" => $this->source->reviewDigest()], "plan" => $plan];
    }

    private function assertQuarantine(MigrationRun $run): void
    {
        $actual = [];
        foreach ($this->ledger->snapshot($run->id())->observations() as $row) {
            if ($row->disposition() !== MigrationDisposition::Quarantined) { continue; }
            RehearsalContract::require($row->mappings() === [], "quarantine_has_target");
            $actual[] = ["source_type" => $row->sourceType(), "source_id" => $row->sourceId(), "payload_hash" => $row->payloadHash(),
                "reason_code" => $row->reasonCode(), "evidence_hash" => $row->payloadHash(), "no_target" => true];
        }
        usort($actual, static fn (array $a, array $b): int => [$a["source_type"], $a["source_id"]] <=> [$b["source_type"], $b["source_id"]]);
        RehearsalContract::equal($actual, $this->source->review["quarantine"], "production_quarantine_changed");
    }

    private function recordFailure(Throwable $failure): void
    {
        $this->evidence->append("production-failure", ["run_id" => $this->run?->id(), "phases" => $this->phases,
            "failure" => RehearsalFailureEvidence::describe($failure, $this->phases), "automatic_restore" => false]);
    }
}
