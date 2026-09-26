<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reconciliation;

use Biblio\Core\Application\Migration\{MigrationLedgerObservation, MigrationLedgerSnapshot, MigrationRun, MigrationRunStatus, MigrationTargetMapping};
use Biblio\Core\Application\Migration\Catalog\CatalogItemPlan;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;

/** Only target inspection sees the old Edition; run accounting stays Item-only. */
final readonly class CommittedItemDependencyInspector implements MigrationTargetInspector
{
    public function __construct(private MigrationTargetInspector $targets, private MigrationLedgerSnapshot $dependencies) {}

    public function exists(MigrationRun $run, MigrationSourceRecord $record, MigrationLedgerObservation $observation,
        MigrationTargetMapping $mapping, MigrationLedgerSnapshot $snapshot): bool
    {
        $old = $this->dependencies->run();
        $plan = $record->typedPlan();
        if (!$plan instanceof CatalogItemPlan || $record->sourceType() !== 'catalog_item'
            || $old->status() !== MigrationRunStatus::Completed || $old->id() === $run->id()
            || !$old->targetUserId()->equals($run->targetUserId()) || !$old->targetLibraryId()->equals($run->targetLibraryId())
            || $old->sourceFamily() !== $run->sourceFamily() || $old->sourceSnapshot() !== $run->sourceSnapshot()
            || $old->sourceFingerprint() !== $run->sourceFingerprint() || $this->dependencies->mappingAnomalies() !== []) {
            return false;
        }
        $matches = [];
        foreach ($this->dependencies->observations() as $dependency) {
            if ($dependency->sourceType() === 'catalog_edition' && $dependency->sourceId() === $plan->editionSourceId()) {
                if ($dependency->processingStatus() !== 'committed' || $dependency->sourceFamily() !== $run->sourceFamily()
                    || $dependency->sourceSnapshot() !== $run->sourceSnapshot()) { return false; }
                $matches[] = $dependency;
            }
        }
        if (count($matches) !== 1) { return false; }
        foreach ($snapshot->observations() as $existing) {
            if ($existing->sourceType() !== 'catalog_item') { return false; }
        }
        return $this->targets->exists($run, $record, $observation, $mapping,
            new MigrationLedgerSnapshot($run, [...$snapshot->observations(), ...$matches], $snapshot->mappingAnomalies()));
    }
}
