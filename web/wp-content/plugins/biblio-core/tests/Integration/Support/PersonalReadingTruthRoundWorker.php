<?php

declare(strict_types=1);

use Biblio\Core\Application\Reading\FinishReadingRoundService;
use Biblio\Core\Application\Reading\ReadingRoundEnd;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalWorkReadingMutationLock;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingRoundRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\WordPress\SystemReadingRoundClock;
use Biblio\Core\Reading\PersonalWorkReadingMutationLock;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundVersion;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;

if ($argc !== 6) {
    fwrite(STDERR, "Expected round, user, work, ready path and release path.\n");
    exit(2);
}

require dirname(__DIR__) . "/bootstrap.php";
[
    ,
    $truthRoundRaceRoundId,
    $truthRoundRaceUserId,
    $truthRoundRaceWorkId,
    $truthRoundRaceReadyPath,
    $truthRoundRaceReleasePath,
] = $argv;

$tables = new CoreTableNames($wpdb->prefix);
$databaseLock = new WpdbPersonalWorkReadingMutationLock($wpdb, $tables);
$lock = new class(
    $databaseLock,
    $truthRoundRaceReadyPath,
    $truthRoundRaceReleasePath
) implements PersonalWorkReadingMutationLock {
    public function __construct(
        private PersonalWorkReadingMutationLock $inner,
        private string $readyPath,
        private string $releasePath
    ) {
    }

    public function acquire(UserId $userId, WorkId $workId): void
    {
        $this->inner->acquire($userId, $workId);
        if (file_put_contents($this->readyPath, "locked") === false) {
            throw new RuntimeException("Could not signal held reading lock.");
        }
        $deadline = microtime(true) + 15;
        while (!is_file($this->releasePath)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Reading lock release timed out.");
            }
            usleep(10_000);
        }
    }
};
$end = new ReadingRoundEnd(
    new ControllableAuthenticatedUser(new UserId($truthRoundRaceUserId)),
    new WpdbReadingRoundRepository($wpdb, $tables),
    new SystemReadingRoundClock(),
    new WpdbTransactionManager($wpdb),
    $lock
);
$round = (new FinishReadingRoundService($end))->finish(
    new ReadingRoundId($truthRoundRaceRoundId),
    new ReadingRoundVersion(1),
    ReadingDate::exact(2026, 9, 9)
);

fwrite(STDOUT, json_encode([
    "status" => "completed",
    "outcome" => $round->outcome()?->value,
], JSON_THROW_ON_ERROR) . "\n");
