<?php

declare(strict_types=1);

use Biblio\Core\Application\Identity\PlatformUserDirectory;
use Biblio\Core\Application\Reading\PersonalReadingTruthRecorder;
use Biblio\Core\Application\Reading\RecordPersonalReadingTruthService;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalReadingTruthRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalWorkReadingMutationLock;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingRoundRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Infrastructure\WordPress\SystemPersonalReadingTruthClock;
use Biblio\Core\Reading\PersonalReadingTruthState;
use Biblio\Core\Reading\PersonalReadingTruthContradiction;
use Biblio\Core\Reading\PersonalWorkReadingMutationLock;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;

if ($argc !== 6 && $argc !== 7) {
    fwrite(
        STDERR,
        "Expected state, user, work, ready path, release path and optional lock-attempt path.\n"
    );
    exit(2);
}

require dirname(__DIR__) . "/bootstrap.php";
[
    ,
    $truthRaceState,
    $truthRaceUserId,
    $truthRaceWorkId,
    $truthRaceReadyPath,
    $truthRaceReleasePath,
] = $argv;
$truthRaceLockAttemptPath = $argv[6] ?? null;

if (file_put_contents($truthRaceReadyPath, "ready") === false) {
    throw new RuntimeException("Could not signal Personal Reading Truth readiness.");
}
$deadline = microtime(true) + 15;
while (!is_file($truthRaceReleasePath)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException("Personal Reading Truth barrier timed out.");
    }
    usleep(10_000);
}

$actorId = new UserId($truthRaceUserId);
$tables = new CoreTableNames($wpdb->prefix);
$repository = new WpdbPersonalReadingTruthRepository($wpdb, $tables);
$transactions = new WpdbTransactionManager($wpdb);
$users = new class implements PlatformUserDirectory {
    public function isActive(UserId $userId): bool { return true; }
};
$databaseLock = new WpdbPersonalWorkReadingMutationLock($wpdb, $tables);
$lock = $truthRaceLockAttemptPath === null
    ? $databaseLock
    : new class(
        $databaseLock,
        $truthRaceLockAttemptPath
    ) implements PersonalWorkReadingMutationLock {
        public function __construct(
            private PersonalWorkReadingMutationLock $inner,
            private string $attemptPath
        ) {
        }

        public function acquire(UserId $userId, WorkId $workId): void
        {
            if (file_put_contents($this->attemptPath, "attempt") === false) {
                throw new RuntimeException("Could not signal truth lock attempt.");
            }
            $this->inner->acquire($userId, $workId);
        }
    };
$service = new RecordPersonalReadingTruthService(
    new ControllableAuthenticatedUser($actorId),
    new PersonalReadingTruthRecorder(
        $users,
        new WpdbWorkRepository($wpdb, $tables),
        new WpdbReadingRoundRepository($wpdb, $tables),
        $repository,
        $lock,
        new SystemPersonalReadingTruthClock()
    ),
    $transactions
);
try {
    $truth = $service->record(
        new WorkId($truthRaceWorkId),
        PersonalReadingTruthState::from($truthRaceState)
    );
    $result = [
        "status" => "recorded",
        "state" => $truth->state()->value,
        "version" => $truth->version()->value(),
    ];
} catch (PersonalReadingTruthContradiction) {
    $result = ["status" => "contradiction"];
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
