<?php

declare(strict_types=1);

use Biblio\Core\Catalog\CatalogRecordAlreadyExists;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitialization;
use Biblio\Core\Catalog\Classification\ClassificationSeedKey;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\IsbnCanonicalizer;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryBookTypeRepository;
use Biblio\Core\Library\LibraryId;

if ($argc !== 7 && $argc !== 8) {
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
$isbnInput = $argv[7] ?? null;

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
    $libraryId = new LibraryId($libraryValue);
    $bookType = (new WpdbLibraryBookTypeRepository(
        $wpdb,
        new CoreTableNames($wpdb->prefix)
    ))->findBySeedKey(
        $libraryId,
        new ClassificationSeedKey("book_type.reading_book")
    );

    if ($bookType === null) {
        throw new RuntimeException("Classification seed is missing.");
    }

    $isbnMetadata = null;
    if ($isbnInput !== null) {
        $parsed = (new IsbnCanonicalizer())->parse($isbnInput);
        $identity = $parsed->identity();
        if ($identity === null) {
            throw new RuntimeException("Worker ISBN input is invalid.");
        }
        $isbnMetadata = $identity->metadata();
    }

    $item = (new ProductionComposition($wpdb))
        ->application()
        ->libraryItemCreation()
        ->addWithNewWorkAndEdition(
            $libraryId,
            new ItemId($itemValue),
            new WorkId($workValue),
            "Concurrent Work",
            new EditionId($editionValue),
            new LibraryCatalogContextInitialization(
                new LibraryCatalogSelection($bookType->id())
            ),
            $isbnMetadata
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
