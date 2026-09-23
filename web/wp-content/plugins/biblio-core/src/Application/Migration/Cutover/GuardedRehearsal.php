<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Identity\MigrationTargetValidator;
use Biblio\Core\Application\Migration\{MigrationDisposition, MigrationLedgerRepository, MigrationRecordOutcome, MigrationRun, MigrationRunStatus};
use Biblio\Core\Application\Migration\Runner\{DeterministicJson, MigrationApplyObserver, MigrationApplyRunner, MigrationPlanningTarget, MigrationRunner, MigrationSourcePackageFactory, MigrationSourceRecord, PreparedMigrationPlan};
use Biblio\Core\Application\Migration\Reconciliation\MigrationReconciliationReport;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Throwable;

/** Only orchestration: every product/ledger mutation stays in MigrationApplyRunner. */
final class GuardedRehearsal implements MigrationApplyObserver
{
    private ?RehearsalAuthorization $authorization = null;
    private string $mode = "";
    private ?PreparedMigrationPlan $prepared = null;
    private ?MigrationRun $run = null;
    /**
     * @var array<string,mixed>|null
     */
    private ?array $checkpoint = null;
    /**
     * @var list<string>
     */
    private array $phases = [];

    public function __construct(
        private readonly ApprovedRehearsalSource $source,
        private readonly MigrationSourcePackageFactory $packages,
        private readonly MigrationRunner $planner,
        private readonly MigrationApplyRunner $apply,
        private readonly MigrationTargetValidator $targets,
        private readonly RehearsalTarget $target,
        private readonly RehearsalBackupStore $backups,
        private readonly MigrationLedgerRepository $ledger,
        private readonly RehearsalProductVerifier $products,
        private readonly RehearsalEvidenceStore $evidence,
        private readonly RehearsalIsolationGuard $isolation,
        private readonly UserId $user,
        private readonly LibraryId $library
    ) {}

    /**
     * No migration writes. Returns material requiring exact operator authorization.
     * @return array<string,mixed>
     */
    public function preflight(): array
    {
        $protected = $this->isolation->fingerprint();
        $this->target->acquire();
        try {
            $this->target->assertEmpty();
            $prepared = $this->prepare();
            $binding = $this->binding($prepared);
            $baseline = $this->target->fingerprint();
            $binding["baseline"] = $baseline;
            $backup = $this->backups->create("PRE_APPLY", $binding);
            RehearsalContract::equal($this->target->fingerprint(), $baseline, "preflight_wrote_target");
            $packet = ["preflight" => $binding, "pre_apply_backup" => $backup];
            $this->evidence->append("rehearsal-preflight", $packet);
            return $packet;
        } finally {
            $this->target->release();
            RehearsalContract::equal($this->isolation->fingerprint(), $protected, "protected_database_changed");
            $this->source->verify($this->packages);
        }
    }

    /**
     * @param array<string,mixed>|null $checkpoint
     * @return array<string,mixed>
     */
    public function execute(RehearsalAuthorization $authorization, string $mode = "apply", ?array $checkpoint = null): array
    {
        RehearsalContract::require(in_array($mode, ["apply", "resume", "replay"], true), "rehearsal_mode_invalid");
        RehearsalContract::require($mode === "apply" || $checkpoint !== null, "checkpoint_required");
        RehearsalContract::require($mode !== "resume" || $authorization->resumePermitted, "resume_not_authorized");
        $this->authorization = $authorization;
        $this->mode = $mode;
        $this->checkpoint = $checkpoint;
        $this->run = null;
        $this->phases = [];
        $protected = $this->isolation->fingerprint();
        $this->target->acquire();
        $started = hrtime(true);
        try {
            $this->backups->verify($authorization->backup, $authorization->preflight);
            $this->evidence->append("rehearsal-attempt-start", ["intent_digest" => $authorization->intentDigest(), "mode" => $mode, "review_id" => $authorization->operatorReviewId]);
            $before = $this->target->fingerprint();
            $result = $this->apply->apply(
                $this->source->intake->extractionRoot(), $this->source->intake->identity()->adapterId(),
                $this->user, $this->library, $authorization->preflight["plan"]["plan_set_digest"],
                observer: $this
            );
            $this->phases[] = "post_apply_quarantine_verification";
            $classification = $result->reconciliation()->accepted() ? $this->classify($result->run()) : "rejected";
            $verification = [];
            $postBackup = null;
            if ($result->run()->status() === MigrationRunStatus::Completed) {
                RehearsalContract::require($classification !== "rejected" && $this->prepared !== null, "reconciliation_rejected");
                $this->phases[] = "post_apply_product_verification";
                $verification = $this->products->verify($result->run(), $this->prepared);
                $this->phases[] = "post_apply_verification";
                if ($mode !== "replay") {
                    $postBackup = $this->backups->create("POST_APPLY", $authorization->preflight + [
                        "completed_run_id" => $result->run()->id(),
                        "reconciliation_sha256" => DeterministicJson::hash($result->reconciliation()->toArray()),
                    ]);
                }
            }
            $after = $this->target->fingerprint();
            foreach ($authorization->preflight["baseline"]["tables"] as $table => $baseline) {
                if (!isset($after["biblio_tables"][$table]) || preg_match('/_biblio_(libraries|library_memberships|personal_library_designations|library_book_types|library_genres|library_subjects)$/D', $table) === 1) {
                    RehearsalContract::equal($after["tables"][$table] ?? null, $baseline, "nonmigration_state_changed");
                }
            }
            if ($mode === "replay") {
                RehearsalContract::equal($after, $before, "replay_changed_target");
                RehearsalContract::require($result->run()->status() === MigrationRunStatus::Completed, "replay_incomplete");
            }
            $nextCheckpoint = [
                "intent_digest" => $authorization->intentDigest(), "run_id" => $result->run()->id(),
                "authorization_digest" => $authorization->authorizationDigest(),
                "status" => $result->run()->status()->value, "fingerprint" => $after,
            ];
            $nextCheckpoint["artifact"] = $this->evidence->append("rehearsal-checkpoint", $nextCheckpoint);
            $receipt = [
                "binding" => $authorization->preflight, "intent_digest" => $authorization->intentDigest(),
                "mode" => $mode, "phases" => $this->phases, "checkpoint" => $nextCheckpoint,
                "pre_backup" => $authorization->backup, "post_backup" => $postBackup,
                "classification" => $classification, "reconciliation" => $result->reconciliation()->toArray(),
                "verification" => $verification, "replay_zero_state_delta" => $mode === "replay",
                "duration_ms" => (hrtime(true) - $started) / 1000000,
                "peak_memory_bytes" => memory_get_peak_usage(true),
            ];
            $receipt["artifact"] = $this->evidence->append("rehearsal-" . $mode, $receipt);
            return $receipt;
        } catch (Throwable $failure) {
            $reason = $failure instanceof RehearsalFailure ? $failure->reason : "apply_or_verification_failed";
            $this->evidence->append("rehearsal-failure", [
                "intent_digest" => $authorization->intentDigest(), "mode" => $mode,
                "phases" => $this->phases, "run_id" => $this->currentRunId(),
                "reason" => $reason,
                "failure" => RehearsalFailureEvidence::describe($failure, $this->phases),
            ]);
            throw new RehearsalFailure($reason, $failure);
        } finally {
            $this->target->release();
            $this->authorization = null;
            RehearsalContract::equal($this->isolation->fingerprint(), $protected, "protected_database_changed");
            $this->source->verify($this->packages);
        }
    }

    public function rollback(RehearsalAuthorization $authorization): void
    {
        $protected = $this->isolation->fingerprint();
        $this->target->acquire();
        try {
            $this->source->verify($this->packages);
            RehearsalContract::equal($this->source->provenance(), $authorization->preflight["source"], "rollback_source_changed");
            RehearsalContract::equal($protected, $authorization->preflight["protected_database"], "protected_database_changed");
            $this->backups->restore($authorization->backup, $authorization->preflight);
            RehearsalContract::equal($this->target->fingerprint(), $authorization->preflight["baseline"], "rollback_baseline_mismatch");
            $this->evidence->append("rehearsal-rollback", ["intent_digest" => $authorization->intentDigest(), "backup" => $authorization->backup, "exact_baseline_restored" => true]);
        } finally {
            $this->target->release();
            RehearsalContract::equal($this->isolation->fingerprint(), $protected, "protected_database_changed");
            $this->source->verify($this->packages);
        }
    }

    public function beforeBegin(PreparedMigrationPlan $prepared): void
    {
        RehearsalContract::require($this->authorization !== null, "rehearsal_authorization_missing");
        $this->source->verify($this->packages);
        $actual = $this->binding($prepared);
        $actual["baseline"] = $this->authorization->preflight["baseline"];
        RehearsalContract::equal($actual, $this->authorization->preflight, "authorized_intent_changed");
        $this->backups->verify($this->authorization->backup, $actual);
        if ($this->mode === "apply") {
            $this->target->assertEmpty();
            RehearsalContract::equal($this->target->fingerprint(), $actual["baseline"], "target_baseline_changed");
        } else {
            RehearsalContract::require($this->checkpoint !== null, "checkpoint_required");
            RehearsalContract::require(is_array($this->checkpoint["artifact"] ?? null), "checkpoint_receipt_required");
            $checkpointMaterial = $this->checkpoint;
            unset($checkpointMaterial["artifact"]);
            RehearsalContract::equal($this->evidence->read($this->checkpoint["artifact"]), $checkpointMaterial, "checkpoint_receipt_changed");
            RehearsalContract::equal($this->checkpoint["authorization_digest"] ?? null, $this->authorization->authorizationDigest(), "resume_authorization_changed");
            RehearsalContract::equal($this->checkpoint["intent_digest"] ?? null, $this->authorization->intentDigest(), "resume_intent_changed");
            RehearsalContract::equal($this->target->fingerprint(), $this->checkpoint["fingerprint"] ?? null, "resume_target_changed");
            $run = $this->ledger->findRun((string) ($this->checkpoint["run_id"] ?? ""));
            RehearsalContract::require($run !== null && $run->status() === ($this->mode === "replay" ? MigrationRunStatus::Completed : MigrationRunStatus::Interrupted), "resume_run_state_invalid");
            RehearsalContract::require($run->targetUserId()->equals($this->user) && $run->targetLibraryId()->equals($this->library), "resume_target_mismatch");
            RehearsalContract::require($run->sourceFamily() === $prepared->inspection()->adapter()->sourceFamily()
                && $run->sourceSnapshot() === $prepared->inspection()->package()->manifestDigest()
                && $run->sourceFingerprint() === $prepared->inspection()->package()->manifestDigest()
                && $run->sourceVersion() === $prepared->inspection()->profile()->sourceVersion()
                && $run->migratorVersion() === $this->apply->migratorVersion(), "resume_run_identity_changed");
        }
        $this->prepared = $prepared;
        $this->phases[] = "preflight_complete";
    }

    public function begun(MigrationRun $run): void
    {
        if ($this->checkpoint !== null) {
            RehearsalContract::require($run->id() === $this->checkpoint["run_id"], "resume_resolved_other_run");
        }
        $this->run = $run;
        $this->phases[] = "run_begun";
    }

    public function committed(MigrationRun $run, MigrationSourceRecord $record, MigrationRecordOutcome $outcome): bool
    {
        unset($run, $record);
        $product = $outcome->mappings() !== [];
        $preserved = $outcome->disposition() === MigrationDisposition::PreservedDeferred;
        $phase = $product ? "product_commit" : "preservation_or_quarantine_commit";
        if (!in_array($phase, $this->phases, true)) {
            $this->phases[] = $phase;
        }
        return $this->mode === "apply" && (
            ($product && $this->authorization?->fault === RehearsalFault::AfterProductCommit)
            || ($preserved && $this->authorization?->fault === RehearsalFault::AfterPreservationCommit)
        );
    }

    public function beforeCompletion(MigrationRun $run, PreparedMigrationPlan $prepared, MigrationReconciliationReport $report): void
    {
        unset($prepared);
        $this->phases[] = "reconciliation";
        $this->evidence->append("rehearsal-reconciliation", ["run_id" => $run->id(), "report" => $report->toArray()]);
        RehearsalContract::require($report->accepted() && $this->classify($run) !== "rejected", "reconciliation_rejected");
        $this->phases[] = "completion";
    }

    private function prepare(): PreparedMigrationPlan
    {
        $this->source->verify($this->packages);
        return $this->planner->prepare(
            $this->planner->inspectSource($this->source->intake->extractionRoot(), $this->source->intake->identity()->adapterId()),
            new MigrationPlanningTarget($this->targets->validate($this->user, $this->library))
        );
    }

    private function currentRunId(): ?string { return $this->run?->id(); }

    /**
     * @return array<string,mixed>
     */
    private function binding(PreparedMigrationPlan $prepared): array
    {
        RehearsalContract::equal($prepared->finalPopulationBundleDigest(), $this->source->bundle->digest(), "prepared_bundle_mismatch");
        $environment = $this->target->identity();
        $plan = RehearsalPlanEvidence::describe($prepared, array_intersect_key($environment, array_flip(["git_sha", "runtime"])));
        RehearsalContract::equal($plan["quarantine"], $this->source->review["quarantine"], "quarantine_not_reviewed");
        return ["environment" => $environment, "source" => $this->source->provenance(), "plan" => $plan,
            "protected_database" => $this->isolation->fingerprint()];
    }

    private function classify(MigrationRun $run): string
    {
        $actual = [];
        foreach ($this->ledger->snapshot($run->id())->observations() as $observation) {
            if ($observation->disposition() !== MigrationDisposition::Quarantined) {
                continue;
            }
            if ($observation->mappings() !== []) {
                return "rejected";
            }
            $actual[] = [
                "source_type" => $observation->sourceType(), "source_id" => $observation->sourceId(),
                "payload_hash" => $observation->payloadHash(), "reason_code" => $observation->reasonCode(),
                "evidence_hash" => $observation->payloadHash(), "no_target" => true,
            ];
        }
        usort($actual, static fn (array $a, array $b): int => [$a["source_type"], $a["source_id"]] <=> [$b["source_type"], $b["source_id"]]);
        return DeterministicJson::hash($actual) !== DeterministicJson::hash($this->source->review["quarantine"])
            ? "rejected" : ($actual === [] ? "accepted" : "accepted_with_reviewed_quarantine");
    }
}
