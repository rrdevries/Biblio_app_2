<?php

declare(strict_types=1);

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbClassificationSeedEvolutionFactory;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Library\LibraryId;

if ($argc !== 4) {
    fwrite(STDERR, "Expected Library ID, ready path and release path.\n");
    exit(2);
}

[, $libraryValue, $readyPath, $releasePath] = $argv;

require dirname(__DIR__) . "/bootstrap.php";

if (file_put_contents($readyPath, "ready") === false) {
    throw new RuntimeException("Could not signal seed-bootstrap readiness.");
}

$deadline = microtime(true) + 15;

while (!is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException("Seed-bootstrap race barrier timed out.");
    }

    usleep(10_000);
}

$tableNames = new CoreTableNames($wpdb->prefix);
$seedEvolution = WpdbClassificationSeedEvolutionFactory::create(
    $wpdb,
    $tableNames
);
$transactionManager = new WpdbTransactionManager($wpdb);

try {
    $transactionManager->run(function () use (
        $seedEvolution,
        $libraryValue
    ): void {
        $seedEvolution->evolve(new LibraryId($libraryValue));
    });
    fwrite(STDOUT, "ok");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ": " . $exception->getMessage());
    exit(1);
}
