<?php

declare(strict_types=1);

use Biblio\Core\Application\Catalog\ItemLocalDetailsRecorder;
use Biblio\Core\Catalog\{ItemCondition,ItemId,ItemLocalDetailsStale,ItemLocalDetailsState,ItemLocalDetailsVersion};
use Biblio\Core\Infrastructure\Persistence\WordPress\{CoreTableNames,WpdbItemLocalDetailsRepository,WpdbItemRepository,WpdbTransactionManager};
use Biblio\Core\Library\LibraryId;

if ($argc !== 7) {
    fwrite(
        STDERR,
        "Expected Library, Item, condition, expected version, ready path and release path.\n"
    );
    exit(2);
}

[
    , $libraryValue, $itemValue, $conditionValue, $expectedValue,
    $readyPath, $releasePath,
] = $argv;
require dirname(__DIR__) . "/bootstrap.php";

if (file_put_contents($readyPath, "ready") === false) {
    throw new RuntimeException("Could not signal Item-local worker readiness.");
}
$deadline = microtime(true) + 15;
while (!is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException("Item-local mutation barrier timed out.");
    }
    usleep(10_000);
}

$tables = new CoreTableNames($wpdb->prefix);
$recorder = new ItemLocalDetailsRecorder(
    new WpdbItemRepository($wpdb, $tables),
    new WpdbItemLocalDetailsRepository($wpdb, $tables)
);
$transactions = new WpdbTransactionManager($wpdb);

try {
    $details = $transactions->run(fn () => $recorder->recordForLibrary(
        new LibraryId($libraryValue),
        new ItemId($itemValue),
        $conditionValue === "unknown"
            ? ItemLocalDetailsState::unknown()
            : new ItemLocalDetailsState(
                condition: ItemCondition::from($conditionValue)
            ),
        $expectedValue === "-"
            ? null
            : new ItemLocalDetailsVersion((int) $expectedValue)
    ));
    $result = [
        "status" => "success",
        "version" => $details?->version()->value(),
    ];
} catch (ItemLocalDetailsStale) {
    $result = ["status" => "stale"];
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ": " . $exception->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
