<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Throwable;

/** PRE recovery requires physical target and owner authority, not a healthy product DB or source planner. */
final readonly class ProductionRollback
{
    public function __construct(private ProductionMigrationTarget $target, private RehearsalBackupStore $backups, private RehearsalEvidenceStore $evidence) {}

    public function restore(ProductionAuthorization $authorization, string $confirmation): void
    {
        $authorization->assertRestoreConfirmation($confirmation);
        $this->target->prepareRestore($authorization);
        $this->target->acquire();
        try {
            $binding = $authorization->packet["binding"];
            $this->backups->verify($authorization->packet["backup"], $binding);
            $this->target->blockWrites();
            $this->evidence->append("production-restore-start", ["authorization_digest" => ProductionAuthorization::digest($authorization->packet), "backup" => $authorization->packet["backup"]]);
            $this->backups->restore($authorization->packet["backup"], $binding);
            RehearsalContract::equal($this->target->fingerprint(), $binding["baseline"], "production_restore_mismatch");
            $this->target->assertWritesBlocked();
            $this->evidence->append("production-restored", ["authorization_digest" => ProductionAuthorization::digest($authorization->packet), "exact_pre_restored" => true, "writes_remain_blocked" => true]);
        } catch (Throwable $failure) {
            $this->evidence->append("production-restore-failed", ["failure" => RehearsalFailureEvidence::describe($failure, ["restore"]), "automatic_restore" => false]);
            throw $failure;
        } finally { $this->target->release(); }
    }
}
