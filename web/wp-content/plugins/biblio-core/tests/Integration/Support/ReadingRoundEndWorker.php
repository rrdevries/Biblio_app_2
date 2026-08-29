<?php

declare(strict_types=1);

use Biblio\Core\Application\Reading\FinishReadingRoundService;
use Biblio\Core\Application\Reading\ReadingRoundEnd;
use Biblio\Core\Application\Reading\StopReadingRoundService;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingRoundRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\WordPress\SystemReadingRoundClock;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundStale;
use Biblio\Core\Reading\ReadingRoundVersion;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;

if ($argc !== 8) {
    fwrite(
        STDERR,
        "Expected action, round, user, expected version, date, ready path and release path.\n"
    );
    exit(2);
}

[
    ,
    $endWorkerAction,
    $roundValue,
    $userValue,
    $expectedVersion,
    $finishedOn,
    $readyPath,
    $releasePath,
] = $argv;

require dirname(__DIR__) . "/bootstrap.php";

if (file_put_contents($readyPath, "ready") === false) {
    throw new RuntimeException("Could not signal Reading Round end readiness.");
}

$deadline = microtime(true) + 15;
while (!is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException("Reading Round end barrier timed out.");
    }

    usleep(10_000);
}

$actor = new ControllableAuthenticatedUser(new UserId($userValue));
$repository = new WpdbReadingRoundRepository(
    $wpdb,
    new CoreTableNames($wpdb->prefix)
);
$end = new ReadingRoundEnd(
    $actor,
    $repository,
    new SystemReadingRoundClock(),
    new WpdbTransactionManager($wpdb)
);
[$year, $month, $day] = array_map(
    static fn (string $component): int => (int) $component,
    explode("-", $finishedOn)
);

try {
    if ($endWorkerAction === "completed") {
        $round = (new FinishReadingRoundService($end))->finish(
            new ReadingRoundId($roundValue),
            new ReadingRoundVersion((int) $expectedVersion),
            ReadingDate::exact($year, $month, $day)
        );
    } elseif ($endWorkerAction === "stopped") {
        $round = (new StopReadingRoundService($end))->stop(
            new ReadingRoundId($roundValue),
            new ReadingRoundVersion((int) $expectedVersion),
            ReadingDate::exact($year, $month, $day)
        );
    } else {
        throw new RuntimeException("Unknown Reading Round end action.");
    }
    $result = [
        "status" => "ended",
        "outcome" => $round->outcome()?->value,
        "version" => $round->version()->value(),
    ];
} catch (ReadingRoundStale $stale) {
    $result = [
        "status" => "stale",
        "outcome" => $stale->current()->outcome()?->value,
        "version" => $stale->current()->version()->value(),
    ];
} catch (ValidationException) {
    $result = ["status" => "validation"];
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
