<?php

declare(strict_types=1);

use Biblio\Core\Application\Metadata\Discovery\BibliographicMaterializationIntent;
use Biblio\Core\Application\Metadata\MetadataCandidateId;
use Biblio\Core\Application\Metadata\MetadataLookupId;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;

if ($argc !== 6) {
    fwrite(STDERR, "Expected user, discovery, candidate, intent and barrier directory.\n");
    exit(2);
}

[, $userValue, $discoveryValue, $candidateValue, $intentValue, $barrierDirectory] = $argv;

require dirname(__DIR__) . "/bootstrap.php";

wp_set_current_user((int) $userValue);
$readyPath = $barrierDirectory . "/ready-" . getmypid();
$releasePath = $barrierDirectory . "/release";
if (file_put_contents($readyPath, "ready") === false) {
    fwrite(STDERR, "Could not signal bibliographic race readiness.");
    exit(1);
}
$deadline = microtime(true) + 15;
while (!is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Bibliographic race barrier timed out.");
        exit(1);
    }
    usleep(10_000);
}

try {
    $result = (new ProductionComposition($wpdb))->application()
        ->bibliographicMaterialization()
        ->materialize(
            new MetadataLookupId($discoveryValue),
            new MetadataCandidateId($candidateValue),
            BibliographicMaterializationIntent::from($intentValue)
        );
    fwrite(STDOUT, json_encode([
        "work_id" => $result->work()->id()->value(),
        "edition_id" => $result->edition()?->id()->value(),
        "reused" => $result->reused(),
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ": " . $exception->getMessage());
    exit(1);
}
