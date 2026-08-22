<?php

declare(strict_types=1);

use Biblio\Core\Application\Notes\DeletePrivateNoteService;
use Biblio\Core\Application\Notes\UpdatePrivateNoteContentService;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPrivateNoteRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\WordPress\SystemPrivateNoteClock;
use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Notes\PrivateNoteNotAvailable;
use Biblio\Core\Notes\PrivateNoteStale;
use Biblio\Core\Notes\PrivateNoteVersion;
use Biblio\Core\Notes\StrictPrivateNoteContentPolicy;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;

if ($argc !== 7) {
    fwrite(STDERR, "Expected action, note, content, ready path and release path.\n");
    exit(2);
}

[, $noteWorkerAction, $noteValue, $noteWorkerContent, $userValue, $readyPath, $releasePath] = $argv;

require dirname(__DIR__) . "/bootstrap.php";

if (file_put_contents($readyPath, "ready") === false) {
    throw new RuntimeException("Could not signal Private Note worker readiness.");
}

$deadline = microtime(true) + 15;
while (!is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException("Private Note mutation barrier timed out.");
    }

    usleep(10_000);
}

$actor = new ControllableAuthenticatedUser(new UserId($userValue));
$policy = new StrictPrivateNoteContentPolicy();
$repository = new WpdbPrivateNoteRepository(
    $wpdb,
    new CoreTableNames($wpdb->prefix),
    $policy
);
$transactions = new WpdbTransactionManager($wpdb);

try {
    if ($noteWorkerAction === 'update') {
        $note = (new UpdatePrivateNoteContentService(
            $actor,
            $repository,
            $policy,
            new SystemPrivateNoteClock(),
            $transactions
        ))->update(
            new PrivateNoteId($noteValue),
            PrivateNoteVersion::initial(),
            $noteWorkerContent
        );
        $result = ["status" => "updated", "version" => $note->version()->value()];
    } elseif ($noteWorkerAction === 'delete') {
        (new DeletePrivateNoteService(
            $actor,
            $repository,
            $transactions
        ))->delete(
            new PrivateNoteId($noteValue),
            PrivateNoteVersion::initial()
        );
        $result = ["status" => "deleted"];
    } else {
        throw new RuntimeException("Unknown Private Note worker action.");
    }
} catch (PrivateNoteStale) {
    $result = ["status" => "stale"];
} catch (PrivateNoteNotAvailable) {
    $result = ["status" => "not_available"];
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
