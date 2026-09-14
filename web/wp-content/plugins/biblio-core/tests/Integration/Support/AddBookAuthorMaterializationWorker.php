<?php

declare(strict_types=1);

use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitialization;
use Biblio\Core\Application\Metadata\{
    AddBookCommitRequest,
    AddBookCommitSelection,
    AddBookObservedMetadata,
    MetadataCandidateId,
    MetadataLookupId
};
use Biblio\Core\Catalog\Classification\{
    LibraryBookTypeId,
    LibraryCatalogSelection
};
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\LibraryId;

if ($argc !== 8) {
    fwrite(
        STDERR,
        "Expected user, Library, Book type, ISBN, lookup, candidate and barrier.\n"
    );
    exit(2);
}

[
    ,
    $userValue,
    $libraryValue,
    $bookTypeValue,
    $isbnValue,
    $lookupValue,
    $candidateValue,
    $barrierDirectory,
] = $argv;

require dirname(__DIR__) . "/bootstrap.php";

wp_set_current_user((int) $userValue);
$readyPath = $barrierDirectory . "/ready-" . getmypid();
$releasePath = $barrierDirectory . "/release";
if (file_put_contents($readyPath, "ready") === false) {
    fwrite(STDERR, "Could not signal Add Book race readiness.");
    exit(1);
}
$deadline = microtime(true) + 15;
while (!is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Add Book race barrier timed out.");
        exit(1);
    }
    usleep(10_000);
}

try {
    $result = (new ProductionComposition($wpdb))->application()
        ->addBookCommit()->commit(
            new LibraryId($libraryValue),
            new AddBookCommitRequest(
                $isbnValue,
                AddBookCommitSelection::candidate(
                    new MetadataLookupId($lookupValue),
                    new MetadataCandidateId($candidateValue)
                ),
                new AddBookObservedMetadata([]),
                new LibraryCatalogContextInitialization(
                    new LibraryCatalogSelection(
                        new LibraryBookTypeId($bookTypeValue)
                    )
                )
            )
        );
    fwrite(STDOUT, json_encode([
        "work_id" => $result->work()->id()->value(),
        "edition_id" => $result->edition()->id()->value(),
        "item_id" => $result->item()->id()->value(),
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ": " . $exception->getMessage());
    exit(1);
}
