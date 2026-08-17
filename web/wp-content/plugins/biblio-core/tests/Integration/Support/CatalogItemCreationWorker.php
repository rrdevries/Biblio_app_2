<?php

declare(strict_types=1);

use Biblio\Core\Catalog\CatalogRecordAlreadyExists;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\LibraryId;

if ($argc !== 7) {
    fwrite(
        STDERR,
        "Expected user, library, item, Work, Edition and barrier directory.\n"
    );
    exit(2);
}

[
    ,
    $userValue,
    $libraryValue,
    $itemValue,
    $workValue,
    $editionValue,
    $barrierDirectory,
] = $argv;

require dirname(__DIR__) . "/bootstrap.php";

wp_set_current_user((int) $userValue);
$readyPath = $barrierDirectory . "/ready-" . getmypid();
$releasePath = $barrierDirectory . "/release";

if (file_put_contents($readyPath, "ready") === false) {
    fwrite(STDERR, "Could not signal catalog race readiness.");
    exit(1);
}

$deadline = microtime(true) + 15;

while (!is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Catalog race barrier timed out.");
        exit(1);
    }

    usleep(10_000);
}

try {
    $item = (new ProductionComposition($wpdb))
        ->application()
        ->libraryItemCreation()
        ->addWithNewWorkAndEdition(
            new LibraryId($libraryValue),
            new ItemId($itemValue),
            new WorkId($workValue),
            "Concurrent Work",
            new EditionId($editionValue)
        );
    fwrite(STDOUT, json_encode([
        "status" => "created",
        "itemId" => $item->id()->value(),
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (CatalogRecordAlreadyExists $exception) {
    fwrite(STDOUT, json_encode([
        "status" => "conflict",
        "reason" => $exception->reason()->value,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ": " . $exception->getMessage());
    exit(1);
}
