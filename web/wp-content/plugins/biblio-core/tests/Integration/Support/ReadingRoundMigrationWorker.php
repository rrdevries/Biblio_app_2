<?php

declare(strict_types=1);

use Biblio\Core\Application\Identity\{
    PersonalMigrationTarget,
    PersonalMigrationTargetReadiness,
    PlatformUserDirectory
};
use Biblio\Core\Application\Migration\{
    CommitMigrationRecordService,
    MigrationClock,
    MigrationRecordOutcome,
    ObserveSourceRecordService
};
use Biblio\Core\Application\Migration\Reading\{
    ReadingRoundMigrationParticipant,
    ReadingRoundMigrationWriter,
    ReadingRoundPlan
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationPlanningTarget,
    MigrationSourceRecord
};
use Biblio\Core\Application\Reading\ReadingRoundCreation;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    CoreTableNames,
    WpdbEditionRepository,
    WpdbItemRepository,
    WpdbMigrationLedgerRepository,
    WpdbPersonalWorkReadingMutationLock,
    WpdbReadingRoundRepository,
    WpdbTransactionManager,
    WpdbWorkRepository
};
use Biblio\Core\Infrastructure\WordPress\{
    OpaqueReadingRoundIdGenerator,
    SystemReadingRoundClock
};
use Biblio\Core\Library\{LibraryId,LibraryName};
use Biblio\Core\Reading\{ReadingDate,ReadingPeriod,ReadingRoundOutcome};

if ($argc !== 8) {
    fwrite(STDERR, "Expected run, user, library, round source, Work source, ready and release.\n");
    exit(2);
}

require dirname(__DIR__) . "/bootstrap.php";
[
    ,
    $readingMigrationRun,
    $readingMigrationUser,
    $readingMigrationLibrary,
    $readingMigrationSource,
    $readingMigrationWorkSource,
    $readingMigrationReady,
    $readingMigrationRelease,
] = $argv;

$clock = new class implements MigrationClock, \Biblio\Core\Reading\ReadingRoundClock {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-15T12:00:00.123456+00:00");
    }
};
$user = new UserId($readingMigrationUser);
$library = new LibraryId($readingMigrationLibrary);
$tables = new CoreTableNames($wpdb->prefix);
$ledger = new WpdbMigrationLedgerRepository($wpdb, $tables);
$run = $ledger->findRun($readingMigrationRun)
    ?? throw new RuntimeException("Migration run is unavailable.");
$record = MigrationSourceRecord::typed(
    ReadingRoundMigrationParticipant::SOURCE_TYPE,
    $readingMigrationSource,
    new ReadingRoundPlan(
        $user,
        $readingMigrationWorkSource,
        ReadingRoundOutcome::Completed,
        ReadingPeriod::ended(null, ReadingDate::year(2020))
    )
);
$observation = (new ObserveSourceRecordService($ledger, $clock))->observe(
    $run,
    $record->sourceType(),
    $record->sourceId(),
    $record->payloadHash(),
    $record->payload()
);
$rounds = new WpdbReadingRoundRepository($wpdb, $tables);
$participant = new ReadingRoundMigrationParticipant(
    new ReadingRoundMigrationWriter(
        $ledger,
        new class implements PlatformUserDirectory {
            public function isActive(UserId $userId): bool { return true; }
        },
        new WpdbWorkRepository($wpdb, $tables),
        new WpdbEditionRepository($wpdb, $tables),
        new WpdbItemRepository($wpdb, $tables),
        $rounds,
        new ReadingRoundCreation(new OpaqueReadingRoundIdGenerator(), $rounds),
        new WpdbPersonalWorkReadingMutationLock($wpdb, $tables),
        new SystemReadingRoundClock()
    )
);
$target = new MigrationPlanningTarget(new PersonalMigrationTarget(
    $user,
    $library,
    new LibraryName("Migration target"),
    new PersonalMigrationTargetReadiness([])
));
$plan = $participant->plan($record, $target);

if (file_put_contents($readingMigrationReady, "ready") === false) {
    throw new RuntimeException("Could not signal ReadingRound migration readiness.");
}
$deadline = microtime(true) + 15;
while (!is_file($readingMigrationRelease)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException("ReadingRound migration barrier timed out.");
    }
    usleep(10_000);
}

try {
    $outcome = (new CommitMigrationRecordService(
        $ledger,
        new WpdbTransactionManager($wpdb),
        $clock
    ))->commit(
        $run,
        $observation,
        fn (): MigrationRecordOutcome => $participant->apply(
            $record,
            $observation,
            $plan,
            $target
        )
    );
    $payload = [
        "status" => "committed",
        "round_id" => $outcome->mappings()[0]->targetId(),
    ];
} catch (ValidationException) {
    $payload = ["status" => "already_committed"];
}

fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR) . "\n");
