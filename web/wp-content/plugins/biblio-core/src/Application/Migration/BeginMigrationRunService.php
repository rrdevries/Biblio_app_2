<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

use Biblio\Core\Application\Identity\PersonalMigrationTargetService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;

final readonly class BeginMigrationRunService
{
    public function __construct(
        private PersonalMigrationTargetService $targets,
        private MigrationLedgerRepository $ledger,
        private TransactionManager $transactions,
        private MigrationClock $clock,
        private MigrationRunIdGenerator $ids
    ) {
    }

    public function begin(
        string $sourceFamily,
        string $sourceSnapshot,
        string $sourceFingerprint,
        ?string $sourceVersion,
        string $migratorVersion,
        UserId $targetUserId,
        LibraryId $targetLibraryId,
        MigrationMode $mode
    ): MigrationRun {
        $at = $this->clock->now();
        $run = MigrationRun::start(
            $this->ids->next(),
            $sourceFamily,
            $sourceSnapshot,
            $sourceFingerprint,
            $sourceVersion,
            $migratorVersion,
            $targetUserId,
            $targetLibraryId,
            $mode,
            $at
        );

        if ($mode === MigrationMode::DryRun) {
            $this->targets->validate($targetUserId, $targetLibraryId);
            return $run;
        }

        return $this->transactions->run(function () use ($run, $targetUserId, $targetLibraryId): MigrationRun {
            $this->targets->validate($targetUserId, $targetLibraryId);
            return $this->ledger->beginOrResume($run);
        });
    }
}
